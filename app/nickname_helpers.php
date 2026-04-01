<?php
/**
 * app/nickname_helpers.php
 * Complete nickname generation and management system
 * Emoji-ready validation (utf8mb4) with grapheme-aware length limits.
 *
 * Name FX integration:
 *  - Loads app/name_effects.php (nfx_*) when available
 *  - get_user_nickname_info() returns:
 *      - 'display_name' (plain text)
 *      - 'effect_code'  (active code or null)
 *      - 'display_html' (SAFE HTML with FX applied; falls back to plain)
 *  - Helpers you can print directly:
 *      - get_user_display_name_html(PDO $pdo, int $user_id): string
 *      - nickname_render_html_for_user(PDO $pdo, int $user_id, ?string $text=null): string
 */

declare(strict_types=1);

/* -----------------------------------------------------------
 * Bootstrap: escape + load Name Effects (optional)
 * ----------------------------------------------------------- */

if (!function_exists('e')) {
    function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/* Try to load Name Effects if not already loaded */
if (!function_exists('nfx_render_html')) {
    $paths = [
        __DIR__ . '/name_effects.php',
        dirname(__DIR__) . '/app/name_effects.php',
        dirname(__DIR__, 2) . '/app/name_effects.php',
    ];
    foreach ($paths as $p) { if (is_file($p)) { require_once $p; break; } }
}

/* Safe fallbacks if name_effects.php isn’t present */
if (!function_exists('nfx_render_html')) {
    /** Fallback: ignore code, just escape nickname */
    function nfx_render_html(string $nickname, ?string $code, bool $includeEmoji = true): string {
        return e($nickname);
    }
}
if (!function_exists('nfx_active_code')) {
    function nfx_active_code(PDO $pdo, int $userId): ?string { return null; }
}
if (!function_exists('nfx_include_emoji')) {
    /** Fallback preference: ON */
    function nfx_include_emoji(PDO $pdo, int $userId): bool { return true; }
}

/* -----------------------------------------------------------
 * Grapheme utilities (emoji-friendly)
 * ----------------------------------------------------------- */

function nick_grapheme_count(string $s): int {
    if (@preg_match('//u', $s) !== 1) return strlen($s); // invalid UTF-8
    if (preg_match_all('/\X/u', $s, $m)) return count($m[0]);
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

function nickname_clean(string $raw): string {
    $s = trim($raw);
    if ($s === '') return '';
    if (class_exists('Normalizer')) {
        /** @psalm-suppress UndefinedClass */
        $s = Normalizer::normalize($s, Normalizer::FORM_C);
    } elseif (function_exists('normalizer_normalize')) {
        $s = normalizer_normalize($s, \Normalizer::FORM_C);
    }
    // collapse internal whitespace (we forbid spaces in validation, but keep it tidy)
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim($s);
}

/* -----------------------------------------------------------
 * Random nickname (ASCII-y)
 * ----------------------------------------------------------- */

function generate_random_nickname(): string {
    $adjectives = ['Swift','Brave','Clever','Mighty','Silent','Golden','Shadow','Fire','Storm','Iron','Wild','Mystic','Frost','Thunder','Crimson','Azure','Lunar','Solar','Ember','Crystal','Venom','Phantom','Void','Primal','Ancient','Noble','Rogue','Elite','Epic','Cosmic','Divine','Savage','Dark','Light','Blood','Steel','Flame','Ice','Wind','Earth','Arcane','Blessed','Cursed','Mad','Fierce','Bold','Rapid'];
    $nouns      = ['Wolf','Eagle','Dragon','Tiger','Bear','Fox','Hawk','Lion','Serpent','Raven','Panther','Falcon','Viper','Phoenix','Griffin','Warrior','Hunter','Mage','Knight','Assassin','Ranger','Paladin','Blade','Shield','Arrow','Spell','Rune','Gem','Star','Moon','Storm','Flame','Frost','Lightning','Shadow','Light','Void','Spirit','Demon','Angel','Beast','Slayer','Guardian','Champion','Legend','Hero'];
    return $adjectives[array_rand($adjectives)] . $nouns[array_rand($nouns)] . random_int(100, 999);
}

/* -----------------------------------------------------------
 * Validation (emoji-aware)
 * ----------------------------------------------------------- */

function validate_nickname(string $nickname, bool $allowEmoji = false): array {
    $errors = [];
    $nickname = nickname_clean($nickname);

    $glen = nick_grapheme_count($nickname);
    if ($glen < 3)  $errors[] = 'Nickname must be at least 3 characters long.';
    if ($glen > 12) $errors[] = 'Nickname must be 12 characters or less.';

    if (preg_match('/\s/u', $nickname)) $errors[] = 'Spaces are not allowed. Use "_" instead.';
    if (preg_match('/[\x{0000}-\x{001F}\x{007F}]/u', $nickname)) $errors[] = 'Invalid control characters.';

    if ($allowEmoji) {
        $invalid = preg_match('/[^'.
            '\p{L}\p{Nd}_' .                // letters, digits, underscore
            '\x{200D}\x{FE0F}' .            // ZWJ + Variation Selector-16
            '\x{1F1E6}-\x{1F1FF}' .         // flags (regional indicators)
            '\x{1F3FB}-\x{1F3FF}' .         // emoji skin tones
            '\x{1F300}-\x{1FAFF}' .         // main emoji blocks
            '\x{2600}-\x{26FF}' .           // misc symbols
            '\x{2700}-\x{27BF}' .           // dingbats
        ']/u', $nickname);
        if ($invalid) $errors[] = 'Only letters, numbers, underscores, and emoji are allowed.';
    } else {
        if (!preg_match('/\A[a-zA-Z0-9_]+\z/u', $nickname)) {
            $errors[] = 'Only letters, numbers, and underscores are allowed.';
        }
    }

    if (preg_match('/__/', $nickname)) $errors[] = 'Nickname cannot contain consecutive underscores.';
    if (preg_match('/^_|_$/u', $nickname)) $errors[] = 'Nickname cannot start or end with an underscore.';

    $banned = ['admin','staff','mod','gm','gamemaster','fuck','shit','damn','bitch'];
    $lower = function_exists('mb_strtolower') ? mb_strtolower($nickname, 'UTF-8') : strtolower($nickname);
    foreach ($banned as $w) { if (strpos($lower, $w) !== false) { $errors[] = 'Nickname contains inappropriate content.'; break; } }

    return $errors;
}

/* -----------------------------------------------------------
 * DB helpers
 * ----------------------------------------------------------- */

function nickname_exists(PDO $pdo, string $nickname, int $exclude_user_id = 0): bool {
    try {
        $st = $pdo->prepare("SELECT id FROM thorium_website.accounts WHERE nickname = ? AND id != ? LIMIT 1");
        $st->execute([$nickname, $exclude_user_id]);
        if ($st->fetchColumn()) return true;
    } catch (\Throwable $e) {}
    try {
        $st = $pdo->prepare("SELECT id FROM accounts WHERE nickname = ? AND id != ? LIMIT 1");
        $st->execute([$nickname, $exclude_user_id]);
        if ($st->fetchColumn()) return true;
    } catch (\Throwable $e) {}
    return false;
}

function generate_unique_nickname(PDO $pdo): string {
    $max = 50; $i = 0;
    do { $nick = generate_random_nickname(); $i++; } while (nickname_exists($pdo, $nick) && $i < $max);
    if ($i >= $max) $nick = generate_random_nickname() . time();
    return $nick;
}

function set_user_nickname(PDO $pdo, int $user_id, string $nickname): bool {
    try {
        $st = $pdo->prepare("UPDATE thorium_website.accounts SET nickname = ?, nickname_changed_at = NOW() WHERE id = ?");
        $st->execute([$nickname, $user_id]);
        if ($st->rowCount() > 0) return true;
    } catch (\Throwable $e) {}
    try {
        $st = $pdo->prepare("UPDATE accounts SET nickname = ?, nickname_changed_at = NOW() WHERE id = ?");
        return $st->execute([$nickname, $user_id]);
    } catch (\Throwable $e) { return false; }
}

function get_user_nickname(PDO $pdo, int $user_id): string {
    try {
        $st = $pdo->prepare("SELECT nickname, username FROM thorium_website.accounts WHERE id = ? LIMIT 1");
        $st->execute([$user_id]);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) return $r['nickname'] ?: $r['username'] ?: "User {$user_id}";
    } catch (\Throwable $e) {}
    try {
        $st = $pdo->prepare("SELECT nickname, username FROM accounts WHERE id = ? LIMIT 1");
        $st->execute([$user_id]);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) return $r['nickname'] ?: $r['username'] ?: "User {$user_id}";
    } catch (\Throwable $e) {
        try {
            $st = $pdo->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
            $st->execute([$user_id]);
            $u = $st->fetchColumn();
            return $u ?: "User {$user_id}";
        } catch (\Throwable $e2) {}
    }
    return "User {$user_id}";
}

/**
 * Extended info with FX applied and emoji-preference respected.
 * Returns:
 *  - nickname, username, last_changed
 *  - display_name (plain)
 *  - effect_code (or null)
 *  - display_html (SAFE HTML)
 */
function get_user_nickname_info(PDO $pdo, int $user_id): array {
    static $cache = [];
    if (isset($cache[$user_id])) return $cache[$user_id];

    $row = null;
    try {
        $st = $pdo->prepare("SELECT nickname, username, nickname_changed_at, nickname_effect_code
                             FROM thorium_website.accounts WHERE id = ? LIMIT 1");
        $st->execute([$user_id]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $e) {}
    if (!$row) {
        try {
            $st = $pdo->prepare("SELECT nickname, username, nickname_changed_at, nickname_effect_code
                                 FROM accounts WHERE id = ? LIMIT 1");
            $st->execute([$user_id]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\Throwable $e) {}
    }

    $plain = $row ? ($row['nickname'] ?: $row['username'] ?: "User {$user_id}") : "User {$user_id}";
    $code  = $row && !empty($row['nickname_effect_code'])
           ? (string)$row['nickname_effect_code']
           : nfx_active_code($pdo, $user_id);

    // Respect user preference for "include effect on emojis"
    $includeEmoji = nfx_include_emoji($pdo, $user_id);
    $html = nfx_render_html($plain, $code, $includeEmoji);

    $out = [
        'nickname'     => $row['nickname'] ?? null,
        'username'     => $row['username'] ?? ($row ? null : $plain),
        'last_changed' => $row['nickname_changed_at'] ?? null,
        'display_name' => $plain,
        'effect_code'  => $code ?: null,
        'display_html' => $html,
    ];
    $cache[$user_id] = $out;
    return $out;
}

/* -----------------------------------------------------------
 * Cooldown
 * ----------------------------------------------------------- */

function get_nickname_history(PDO $pdo, int $user_id, int $limit = 10): array {
    // Try prefixed table first, then unprefixed fallback
    try {
        $st = $pdo->prepare("SELECT old_nickname, new_nickname, changed_at, changed_by_admin, admin_id
                             FROM thorium_website.nickname_history
                             WHERE user_id = ?
                             ORDER BY changed_at DESC
                             LIMIT ?");
        $st->bindValue(1, $user_id, PDO::PARAM_INT);
        $st->bindValue(2, $limit, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows) return $rows;
    } catch (\Throwable $e) {}
    try {
        $st = $pdo->prepare("SELECT old_nickname, new_nickname, changed_at, changed_by_admin, admin_id
                             FROM nickname_history
                             WHERE user_id = ?
                             ORDER BY changed_at DESC
                             LIMIT ?");
        $st->bindValue(1, $user_id, PDO::PARAM_INT);
        $st->bindValue(2, $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) { return []; }
}

function can_change_nickname(PDO $pdo, int $user_id, int $cooldown_days = 30): array {
    $info = get_user_nickname_info($pdo, $user_id);
    if (empty($info['last_changed'])) return ['can_change'=>true,'reason'=>''];

    $history = get_nickname_history($pdo, $user_id, 1);
    if (empty($history)) return ['can_change'=>true,'reason'=>'First nickname change is free!'];

    $last = strtotime((string)$info['last_changed']);
    $end  = $last + ($cooldown_days * 86400);
    $now  = time();
    if ($now >= $end) return ['can_change'=>true,'reason'=>''];

    $days_left = (int)ceil(($end - $now)/86400);
    return ['can_change'=>false,'reason'=>"You can change your nickname again in {$days_left} day(s).",'days_left'=>$days_left];
}

/* -----------------------------------------------------------
 * Change nickname (flexible signature)
 * ----------------------------------------------------------- */

function change_user_nickname(
    PDO $pdo,
    int $user_id,
    string $new_nickname,
    $admin_or_options = null,
    $maybe_options = null
): array {
    $admin_id = null; $allowEmoji = false; $cooldown = 30;

    if (is_array($admin_or_options)) {
        $opts = $admin_or_options;
        if (array_key_exists('admin_id', $opts))       $admin_id   = ($opts['admin_id'] === null) ? null : (int)$opts['admin_id'];
        if (array_key_exists('allow_emoji', $opts))    $allowEmoji = (bool)$opts['allow_emoji'];
        if (array_key_exists('cooldown_days', $opts))  $cooldown   = max(0, (int)$opts['cooldown_days']);
    } elseif (is_bool($admin_or_options)) {
        $allowEmoji = $admin_or_options;
    } elseif (is_int($admin_or_options)) {
        $admin_id = $admin_or_options;
    }

    if (is_array($maybe_options)) {
        $opts = $maybe_options;
        if (array_key_exists('allow_emoji', $opts))    $allowEmoji = (bool)$opts['allow_emoji'];
        if (array_key_exists('cooldown_days', $opts))  $cooldown   = max(0, (int)$opts['cooldown_days']);
        if (array_key_exists('admin_id', $opts))       $admin_id   = ($opts['admin_id'] === null) ? null : (int)$opts['admin_id'];
    } elseif (is_bool($maybe_options)) {
        $allowEmoji = $maybe_options;
    }

    $new_nickname = nickname_clean($new_nickname);

    $errs = validate_nickname($new_nickname, $allowEmoji);
    if ($errs) return ['success'=>false,'errors'=>$errs];

    if (nickname_exists($pdo, $new_nickname, $user_id))
        return ['success'=>false,'errors'=>['This nickname is already taken.']];

    if ($admin_id === null) {
        $rl = can_change_nickname($pdo, $user_id, $cooldown);
        if (!$rl['can_change']) return ['success'=>false,'errors'=>[$rl['reason']]];
    }

    $old = get_user_nickname_info($pdo, $user_id)['nickname'] ?? null;

    try {
        $pdo->beginTransaction();

        if (!set_user_nickname($pdo, $user_id, $new_nickname)) {
            $pdo->rollBack(); return ['success'=>false,'errors'=>['Failed to update nickname in database.']];
        }

        // Insert history (try prefixed, then unprefixed)
        $inserted = false;
        try {
            $st = $pdo->prepare("INSERT INTO thorium_website.nickname_history
                                 (user_id, old_nickname, new_nickname, changed_by_admin, admin_id)
                                 VALUES (?, ?, ?, ?, ?)");
            $st->execute([$user_id, $old, $new_nickname, $admin_id ? 1 : 0, $admin_id]);
            $inserted = true;
        } catch (\Throwable $e) { /* try fallback */ }
        if (!$inserted) {
            try {
                $st = $pdo->prepare("INSERT INTO nickname_history
                                     (user_id, old_nickname, new_nickname, changed_by_admin, admin_id)
                                     VALUES (?, ?, ?, ?, ?)");
                $st->execute([$user_id, $old, $new_nickname, $admin_id ? 1 : 0, $admin_id]);
            } catch (\Throwable $e2) {
                // history is best-effort; do not fail the change
                error_log("Nickname history insert failed: ".$e2->getMessage());
            }
        }

        $pdo->commit();
        return ['success'=>true,'message'=>'Nickname changed successfully!','old_nickname'=>$old,'new_nickname'=>$new_nickname];
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Nickname change error: ".$e->getMessage());
        return ['success'=>false,'errors'=>['Database error occurred.']];
    }
}

/* -----------------------------------------------------------
 * Ensure/generate convenience
 * ----------------------------------------------------------- */

function ensure_user_nickname(PDO $pdo, int $user_id): string {
    $info = get_user_nickname_info($pdo, $user_id);
    if (!empty($info['nickname'])) return $info['nickname'];
    $nn = generate_unique_nickname($pdo);
    if (set_user_nickname($pdo, $user_id, $nn)) return $nn;
    return $info['username'];
}

/* -----------------------------------------------------------
 * Name FX printing helpers (centralized)
 * ----------------------------------------------------------- */

function get_user_display_name_html(PDO $pdo, int $user_id): string {
    $info = get_user_nickname_info($pdo, $user_id);
    return (string)$info['display_html'];
}

function get_user_display_name_plain(PDO $pdo, int $user_id): string {
    $info = get_user_nickname_info($pdo, $user_id);
    return (string)$info['display_name'];
}

/**
 * Render arbitrary label for a user with their active effect and emoji-preference.
 * If $text is null, uses that user's nickname/username.
 */
function nickname_render_html_for_user(PDO $pdo, int $user_id, ?string $text = null): string {
    $label = $text ?? get_user_nickname($pdo, $user_id);
    $code  = nfx_active_code($pdo, $user_id);
    $includeEmoji = nfx_include_emoji($pdo, $user_id);
    return nfx_render_html($label, $code, $includeEmoji);
}
