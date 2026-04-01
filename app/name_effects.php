<?php
/**
 * app/name_effects.php
 * Safe "Name Effects" (display-name flair)
 *
 * - Supports VIP-gated, staff-only, and PROMO-only effects.
 * - Works with thorium_website.* tables OR unprefixed tables (tries both).
 * - Built-ins remain available if DB exists but is missing some codes (DB wins on conflicts).
 * - Codes are case-insensitive (stored/compared in lowercase).
 * - Per-user toggle "nickname_effect_include_emoji" (defaults to ON if column missing).
 *
 * Gating rules:
 *   staff_only=1  → staff only (VIP/promo ignored)
 *   promo_only=1  → ONLY explicit unlock (or staff) grants access; VIP ignored
 *   else          → VIP >= min_vip OR explicit unlock (or staff)
 */

declare(strict_types=1);

if (!function_exists('e')) {
  function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/** ---------- Built-in fallback effects (expanded) ---------- */
function nfx_builtin(): array {
  return [
    // Originals (stronger palettes; motion handled by CSS)
    'emerald' => ['code'=>'emerald','label'=>'Emerald Glow','description'=>'Soft emerald gradient glow','min_vip'=>0,'staff_only'=>0,'promo_only'=>0,'is_active'=>1],
    'ember'   => ['code'=>'ember','label'=>'Ember','description'=>'Warm ember gradient + glow','min_vip'=>0,'staff_only'=>0,'promo_only'=>0,'is_active'=>1],
    'aqua'    => ['code'=>'aqua','label'=>'Aqua Mist','description'=>'Cool aqua gradient','min_vip'=>0,'staff_only'=>0,'promo_only'=>0,'is_active'=>1],
    'gold'    => ['code'=>'gold','label'=>'Gilded','description'=>'Gold foil sheen','min_vip'=>3,'staff_only'=>0,'promo_only'=>0,'is_active'=>1],
    'rainbow' => ['code'=>'rainbow','label'=>'Prismatic','description'=>'Animated rainbow shimmer','min_vip'=>4,'staff_only'=>0,'promo_only'=>0,'is_active'=>1],
    'ice'     => ['code'=>'ice','label'=>'Frosted','description'=>'Icy blue-white shine','min_vip'=>0,'staff_only'=>0,'promo_only'=>0,'is_active'=>1],

    // Staff
    'gm-aegis'=> ['code'=>'gm-aegis','label'=>'GM Aegis','description'=>'Royal ward: navy→cyan with gold glint (staff only)','min_vip'=>0,'staff_only'=>1,'promo_only'=>0,'is_active'=>1],

    // New uniques — clean, readable, subtle motion
    'sapphire'   => ['code'=>'sapphire','label'=>'Sapphire Depth','description'=>'Deep blue with cool glint','min_vip'=>1,'staff_only'=>0,'promo_only'=>0,'is_active'=>1],
    'amethyst'   => ['code'=>'amethyst','label'=>'Amethyst Veil','description'=>'Rich violet with soft light edge','min_vip'=>1,'staff_only'=>0,'promo_only'=>0,'is_active'=>1],
    'crimson'    => ['code'=>'crimson','label'=>'Crimson Royal','description'=>'Velvet red core with rose highlight','min_vip'=>1,'staff_only'=>0,'promo_only'=>0,'is_active'=>1],
    'rose-gold'  => ['code'=>'rose-gold','label'=>'Rose Gold','description'=>'Warm rose → champagne sweep','min_vip'=>3,'staff_only'=>0,'promo_only'=>0,'is_active'=>1],
    'obsidian'   => ['code'=>'obsidian','label'=>'Obsidian Sheen','description'=>'Dark slate with steel highlight','min_vip'=>2,'staff_only'=>0,'promo_only'=>0,'is_active'=>1],
    'aurora'     => ['code'=>'aurora','label'=>'Aurora','description'=>'Teal→indigo aurora drift','min_vip'=>4,'staff_only'=>0,'promo_only'=>0,'is_active'=>1],
    'arcane'     => ['code'=>'arcane','label'=>'Arcane Flux','description'=>'Cyan→violet arcane glow','min_vip'=>2,'staff_only'=>0,'promo_only'=>0,'is_active'=>1],
    'voidlight'  => ['code'=>'voidlight','label'=>'Voidlight','description'=>'Violet-black core with neon rim','min_vip'=>0,'staff_only'=>0,'promo_only'=>1,'is_active'=>1],
    'sunset'     => ['code'=>'sunset','label'=>'Sunset Mirage','description'=>'Peach→rose dusk sweep','min_vip'=>1,'staff_only'=>0,'promo_only'=>0,'is_active'=>1],
    'platinum'   => ['code'=>'platinum','label'=>'Platinum','description'=>'Brushed platinum chrome (darker mids)','min_vip'=>2,'staff_only'=>0,'promo_only'=>0,'is_active'=>1],
    'neonwire'   => ['code'=>'neonwire','label'=>'Neon Wire','description'=>'Electric cyan→magenta run','min_vip'=>3,'staff_only'=>0,'promo_only'=>0,'is_active'=>1],
    'toxin'      => ['code'=>'toxin','label'=>'Toxin','description'=>'Neon acid-green sting','min_vip'=>2,'staff_only'=>0,'promo_only'=>0,'is_active'=>1],
  ];
}

/** ---------- Load effects from DB; merge with built-ins (DB wins) ---------- */
function nfx_all(PDO $pdo): array {
  $builtins = nfx_builtin();
  $dbOut = [];

  // Try prefixed/unprefixed, with/without promo_only column
  $queries = [
    "SELECT code,label,description,min_vip,staff_only,promo_only,is_active FROM thorium_website.name_effects WHERE is_active=1",
    "SELECT code,label,description,min_vip,staff_only,is_active FROM thorium_website.name_effects WHERE is_active=1",
    "SELECT code,label,description,min_vip,staff_only,promo_only,is_active FROM name_effects WHERE is_active=1",
    "SELECT code,label,description,min_vip,staff_only,is_active FROM name_effects WHERE is_active=1",
  ];

  foreach ($queries as $sql) {
    try {
      $st = $pdo->query($sql);
      $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
      foreach ($rows as $r) {
        $code = strtolower(trim((string)$r['code']));
        if ($code === '') continue;
        $dbOut[$code] = [
          'code'        => $code,
          'label'       => (string)($r['label'] ?? ''),
          'description' => (string)($r['description'] ?? ''),
          'min_vip'     => (int)($r['min_vip'] ?? 0),
          'staff_only'  => !empty($r['staff_only']) ? 1 : 0,
          'promo_only'  => !empty($r['promo_only']) ? 1 : 0,
          'is_active'   => 1,
        ];
      }
      if ($dbOut) break; // stop after first that returned rows
    } catch (\Throwable $e) { /* try next query */ }
  }

  $out = $dbOut ? ($dbOut + $builtins) : $builtins;

  // Ensure keys exist
  foreach ($out as $k => $v) {
    $out[$k]['promo_only'] = (int)($v['promo_only'] ?? 0);
    $out[$k]['staff_only'] = (int)($v['staff_only'] ?? 0);
    $out[$k]['min_vip']    = (int)($v['min_vip'] ?? 0);
    $out[$k]['is_active']  = 1;
  }
  return $out;
}

/** ---------- Unlocks (explicit promo unlocks) ---------- */
function nfx_user_unlocks(PDO $pdo, int $userId): array {
  foreach ([
    "SELECT effect_code FROM thorium_website.name_effect_unlocks WHERE user_id=?",
    "SELECT effect_code FROM name_effect_unlocks WHERE user_id=?",
  ] as $sql) {
    try {
      $st = $pdo->prepare($sql);
      $st->execute([$userId]);
      $codes = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
      if ($codes) {
        $codes = array_map(fn($c)=>strtolower(trim((string)$c)), $codes);
        return array_fill_keys($codes, true);
      }
    } catch (\Throwable $e) { /* try next */ }
  }
  return [];
}

/** ---------- Active effect on account ---------- */
function nfx_active_code(PDO $pdo, int $userId): ?string {
  foreach ([
    "SELECT nickname_effect_code FROM thorium_website.accounts WHERE id=? LIMIT 1",
    "SELECT nickname_effect_code FROM accounts WHERE id=? LIMIT 1",
  ] as $sql) {
    try {
      $st = $pdo->prepare($sql);
      $st->execute([$userId]);
      $code = $st->fetchColumn();
      if ($code !== false && $code !== null && $code !== '') {
        $code = strtolower(trim((string)$code));
        return $code !== '' ? $code : null;
      }
    } catch (\Throwable $e) {}
  }
  return null;
}

/** ---------- Per-user "include emoji" preference (default ON) ---------- */
function nfx_include_emoji(PDO $pdo, int $userId): bool {
  foreach ([
    "SELECT nickname_effect_include_emoji FROM thorium_website.accounts WHERE id=? LIMIT 1",
    "SELECT nickname_effect_include_emoji FROM accounts WHERE id=? LIMIT 1",
  ] as $sql) {
    try {
      $st = $pdo->prepare($sql);
      $st->execute([$userId]);
      $val = $st->fetchColumn();
      if ($val === false || $val === null) continue;
      return ((int)$val) === 1;
    } catch (\Throwable $e) { /* column may not exist */ }
  }
  return true;
}
function nfx_set_include_emoji(PDO $pdo, int $userId, bool $include): bool {
  $b = $include ? 1 : 0;
  $ok = false;
  foreach ([
    "UPDATE thorium_website.accounts SET nickname_effect_include_emoji=:b WHERE id=:id",
    "UPDATE accounts SET nickname_effect_include_emoji=:b WHERE id=:id",
  ] as $sql) {
    try {
      $st = $pdo->prepare($sql);
      $st->execute([':b'=>$b, ':id'=>$userId]);
      if ($st->rowCount() >= 0) $ok = true;
    } catch (\Throwable $e) { /* column may not exist */ }
  }
  return $ok;
}

/** ---------- Set active effect (raw) ---------- */
function nfx_set_active_raw(PDO $pdo, int $userId, ?string $code): bool {
  foreach ([
    "UPDATE thorium_website.accounts SET nickname_effect_code=:c WHERE id=:id",
    "UPDATE accounts SET nickname_effect_code=:c WHERE id=:id",
  ] as $sql) {
    try {
      $st = $pdo->prepare($sql);
      $st->execute([':c'=>$code, ':id'=>$userId]);
      if ($st->rowCount() > 0) return true;
    } catch (\Throwable $e) {}
  }
  return false;
}

/** ---------- Grant/Revoke explicit unlock ---------- */
function nfx_grant(PDO $pdo, int $userId, string $code, ?int $grantedBy=null, string $reason=''): bool {
  $code = strtolower(trim($code));
  foreach ([
    "INSERT INTO thorium_website.name_effect_unlocks (user_id,effect_code,granted_by,reason) VALUES (?,?,?,?)",
    "INSERT INTO name_effect_unlocks (user_id,effect_code,granted_by,reason) VALUES (?,?,?,?)",
  ] as $sql) {
    try {
      $st = $pdo->prepare($sql);
      return $st->execute([$userId, $code, $grantedBy, $reason]);
    } catch (\Throwable $e) { /* try next */ }
  }
  return false;
}
function nfx_revoke(PDO $pdo, int $userId, string $code): bool {
  $code = strtolower(trim($code));
  foreach ([
    "DELETE FROM thorium_website.name_effect_unlocks WHERE user_id=? AND effect_code=?",
    "DELETE FROM name_effect_unlocks WHERE user_id=? AND effect_code=?",
  ] as $sql) {
    try {
      $st = $pdo->prepare($sql);
      $st->execute([$userId, $code]);
      if ($st->rowCount() > 0) return true;
    } catch (\Throwable $e) { /* try next */ }
  }
  return false;
}

/** ---------- Usability rules (VIP / staff / promo) ---------- */
function nfx_usable_for_user(array $effect, int $vipLevel, bool $isStaff, array $unlockedSet): bool {
  if (!empty($effect['staff_only'])) return $isStaff;
  if ($isStaff) return true;
  if (!empty($effect['promo_only'])) {
    return isset($unlockedSet[strtolower($effect['code'])]);
  }
  if ($vipLevel >= (int)($effect['min_vip'] ?? 0)) return true;
  return isset($unlockedSet[strtolower($effect['code'])]);
}

/** ---------- Guarded setter ---------- */
function nfx_set_active_guarded(PDO $pdo, int $userId, ?string $code, array $context): array {
  $vip   = (int)($context['vip']   ?? 0);
  $staff = (bool)($context['staff'] ?? false);

  if ($code === null || $code === '' || strtolower($code) === 'none') {
    nfx_set_active_raw($pdo, $userId, null);
    return ['ok'=>true, 'message'=>'Name effect cleared.'];
  }

  if (!preg_match('/^[a-z0-9\-]{2,32}$/i', $code)) {
    return ['ok'=>false, 'error'=>'Invalid effect code.'];
  }

  $code = strtolower(trim($code));
  $all = nfx_all($pdo);
  if (empty($all[$code])) {
    return ['ok'=>false, 'error'=>'Unknown or inactive effect.'];
  }
  $unlocked = nfx_user_unlocks($pdo, $userId);
  if (!nfx_usable_for_user($all[$code], $vip, $staff, $unlocked)) {
    return ['ok'=>false, 'error'=>'That effect is locked for your account.'];
  }

  if (!nfx_set_active_raw($pdo, $userId, $code)) {
    return ['ok'=>false, 'error'=>'Failed to update effect.'];
  }
  return ['ok'=>true, 'message'=>'Name effect updated.'];
}

/** ---------- Emoji-safe render (respects includeEmoji toggle) ---------- */
function nfx_split_emoji_runs(string $s): array {
  $clusters = [];
  if (preg_match_all('/\X/u', $s, $m)) $clusters = $m[0];
  else $clusters = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];

  $isEmojiCluster = static function(string $g): bool {
    if (preg_match('/[\x{200D}\x{FE0F}]/u', $g)) return true; // ZWJ or VS16
    if (preg_match('/[\x{1F1E6}-\x{1F1FF}]/u', $g)) return true; // flags
    if (preg_match('/[\x{1F300}-\x{1FAFF}]/u', $g)) return true; // emoji blocks
    if (preg_match('/[\x{231A}-\x{231B}\x{23E9}-\x{23FA}\x{24C2}\x{25AA}-\x{27BF}\x{2934}-\x{2935}\x{2B05}-\x{2B55}\x{3030}\x{303D}\x{3297}\x{3299}\x{2763}-\x{2764}]/u', $g)) return true;
    return false;
  };

  $runs = []; $currType = null; $buf = '';
  foreach ($clusters as $gr) {
    $isE = $isEmojiCluster($gr);
    if ($currType === null) { $currType = $isE; $buf = $gr; continue; }
    if ($isE === $currType) { $buf .= $gr; continue; }
    $runs[] = ['text'=>$buf, 'is_emoji'=>$currType]; $currType = $isE; $buf = $gr;
  }
  if ($buf !== '') $runs[] = ['text'=>$buf, 'is_emoji'=>$currType];
  return $runs;
}

function nfx_render_html(string $nickname, ?string $code, bool $includeEmoji = true): string {
  $nickname = (string)$nickname;
  $code     = $code ? strtolower(trim($code)) : '';
  if ($code === '' || !preg_match('/^[a-z0-9\-]{2,32}$/i', $code)) {
    return e($nickname);
  }

  if ($includeEmoji) {
    return '<span class="nfx nfx-'.e($code).'">'.e($nickname).'</span>';
  }

  $out = '';
  foreach (nfx_split_emoji_runs($nickname) as $run) {
    if (!empty($run['is_emoji'])) {
      $out .= '<span class="nfx-emoji-plain">'.e($run['text']).'</span>';
    } else {
      if ($run['text'] !== '') {
        $out .= '<span class="nfx nfx-'.e($code).'">'.e($run['text']).'</span>';
      }
    }
  }
  return $out !== '' ? $out : e($nickname);
}

/** ---------- Partition for panel ---------- */
function nfx_partition_for_user(PDO $pdo, int $userId, int $vipLevel, bool $isStaff): array {
  $effects  = nfx_all($pdo);
  $unlocked = nfx_user_unlocks($pdo, $userId);

  $usable = []; $locked = [];
  foreach ($effects as $code => $eff) {
    if (!empty($eff['staff_only']) && !$isStaff) continue; // hide staff-only from non-staff
    $canUse = nfx_usable_for_user($eff, $vipLevel, $isStaff, $unlocked);
    if ($canUse) $usable[$code] = $eff; else $locked[$code] = $eff;
  }
  return ['usable'=>$usable, 'locked'=>$locked];
}

/** ---------- Upsert helper for adding/editing effects from PHP ---------- */
function nfx_save_effect(PDO $pdo, array $eff): bool {
  $code = strtolower(trim((string)($eff['code'] ?? '')));
  if ($code === '') return false;

  $label = (string)($eff['label'] ?? $code);
  $desc  = (string)($eff['description'] ?? '');
  $minV  = (int)($eff['min_vip'] ?? 0);
  $so    = !empty($eff['staff_only']) ? 1 : 0;
  $po    = !empty($eff['promo_only']) ? 1 : 0;
  $act   = !isset($eff['is_active']) ? 1 : ((int)$eff['is_active'] ? 1 : 0);

  $pairs = [
    "INSERT INTO thorium_website.name_effects (code,label,description,min_vip,staff_only,promo_only,is_active)
     VALUES (?,?,?,?,?,?,?)
     ON DUPLICATE KEY UPDATE label=VALUES(label),description=VALUES(description),
                             min_vip=VALUES(min_vip),staff_only=VALUES(staff_only),
                             promo_only=VALUES(promo_only),is_active=VALUES(is_active)",
    "INSERT INTO name_effects (code,label,description,min_vip,staff_only,promo_only,is_active)
     VALUES (?,?,?,?,?,?,?)
     ON DUPLICATE KEY UPDATE label=VALUES(label),description=VALUES(description),
                             min_vip=VALUES(min_vip),staff_only=VALUES(staff_only),
                             promo_only=VALUES(promo_only),is_active=VALUES(is_active)",
  ];

  foreach ($pairs as $sql) {
    try {
      $st = $pdo->prepare($sql);
      if ($st->execute([$code,$label,$desc,$minV,$so,$po,$act])) return true;
    } catch (\Throwable $e) { /* try next */ }
  }
  return false;
}

/** ---------- Optional: seed DB with built-ins (safe to run multiple times) ---------- */
function nfx_seed_defaults(PDO $pdo): int {
  $n = 0;
  foreach (nfx_builtin() as $eff) {
    if (nfx_save_effect($pdo, $eff)) $n++;
  }
  return $n;
}

/** ---------- CSS (prints once) — seamlessly tiling gradients ---------- */
if (!function_exists('nfx_print_styles_once')) {
  function nfx_print_styles_once(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    echo '<style id="nfx-styles">
    .nfx {
      background-size: 220% 220%;
      background-position: 0% 0%;
      -webkit-background-clip: text; background-clip: text;
      -webkit-text-fill-color: transparent; color: transparent;
      text-shadow: 0 0 12px rgba(255,255,255,.08);
      animation: nfx-flow 4s linear infinite !important;
    }

    @keyframes nfx-flow {
      0% { background-position: 0% 0%; }
      50% { background-position: 50% 0%; }
      100% { background-position: 100% 0%; }
    }
    .nfx-emoji-plain { 
      color: currentColor; 
      -webkit-text-fill-color: currentColor; 
      text-shadow: none !important; 
      filter: none !important; 
      background: none !important; 
      mix-blend-mode: normal !important; 
    }

    /* Seamlessly tiling gradients (pattern repeats 0-50% and 50-100%) */
    .nfx.nfx-emerald {
      background-image: linear-gradient(90deg, 
        #00f2a6 0%, #04ed9f 8%, #0dc88e 16%, #1fbd7d 24%, #3dd468 32%, #70e549 40%, #a8ff38 48%,
        #70e549 52%, #3dd468 60%, #1fbd7d 68%, #0dc88e 76%, #04ed9f 84%, #00f2a6 92%, #00f2a6 100%
      );
    }
    .nfx.nfx-ember {
      background-image: linear-gradient(90deg,
        #ff9c33 0%, #ff7a22 8%, #ff5c15 16%, #ff4d0f 24%, #ff6b3d 32%, #ffaa70 40%, #ffe0b8 48%,
        #ffaa70 52%, #ff6b3d 60%, #ff4d0f 68%, #ff5c15 76%, #ff7a22 84%, #ff9c33 92%, #ff9c33 100%
      );
    }
    .nfx.nfx-aqua {
      background-image: linear-gradient(90deg,
        #00e6ff 0%, #00d3f0 8%, #00b8dd 16%, #00a0cc 24%, #20bbdd 32%, #60d9f2 40%, #d0f7ff 48%,
        #60d9f2 52%, #20bbdd 60%, #00a0cc 68%, #00b8dd 76%, #00d3f0 84%, #00e6ff 92%, #00e6ff 100%
      );
    }
    .nfx.nfx-gold {
      background-image: linear-gradient(90deg,
        #fff08f 0%, #f5de70 8%, #eacc55 16%, #d8a825 24%, #edb84a 32%, #ffe885 40%, #fff8d0 48%,
        #ffe885 52%, #edb84a 60%, #d8a825 68%, #eacc55 76%, #f5de70 84%, #fff08f 92%, #fff08f 100%
      );
    }
    .nfx.nfx-ice {
      background-image: linear-gradient(90deg,
        #ffffff 0%, #e8f4ff 8%, #c8e8ff 16%, #a8d9ff 24%, #b0e2ff 32%, #d0f0ff 40%, #f8fcff 48%,
        #d0f0ff 52%, #b0e2ff 60%, #a8d9ff 68%, #c8e8ff 76%, #e8f4ff 84%, #ffffff 92%, #ffffff 100%
      );
    }
    .nfx.nfx-sapphire {
      background-image: linear-gradient(90deg,
        #00a2ff 0%, #0088e8 8%, #0070d0 16%, #005ec8 24%, #3080ff 32%, #70b0ff 40%, #b0d8ff 48%,
        #70b0ff 52%, #3080ff 60%, #005ec8 68%, #0070d0 76%, #0088e8 84%, #00a2ff 92%, #00a2ff 100%
      );
    }
    .nfx.nfx-amethyst {
      background-image: linear-gradient(90deg,
        #b47fff 0%, #a065ea 8%, #8f4dd8 16%, #7d3bc8 24%, #9558e8 32%, #b888ff 40%, #dcc0ff 48%,
        #b888ff 52%, #9558e8 60%, #7d3bc8 68%, #8f4dd8 76%, #a065ea 84%, #b47fff 92%, #b47fff 100%
      );
    }
    .nfx.nfx-crimson {
      background-image: linear-gradient(90deg,
        #ff3b3b 0%, #eb2842 8%, #d01a38 16%, #b8102e 24%, #d84560 32%, #ff7895 40%, #ffb8c8 48%,
        #ff7895 52%, #d84560 60%, #b8102e 68%, #d01a38 76%, #eb2842 84%, #ff3b3b 92%, #ff3b3b 100%
      );
    }
    .nfx.nfx-rose-gold {
      background-image: linear-gradient(90deg,
        #ffd1c1 0%, #f0b8a0 8%, #d89888 16%, #c87d75 24%, #e0a8a8 32%, #f8d0c8 40%, #fff0e8 48%,
        #f8d0c8 52%, #e0a8a8 60%, #c87d75 68%, #d89888 76%, #f0b8a0 84%, #ffd1c1 92%, #ffd1c1 100%
      );
    }
    .nfx.nfx-obsidian {
      background-image: linear-gradient(90deg,
        #a3b1c6 0%, #8599ad 8%, #677f95 16%, #4f6a7f 24%, #6880a0 32%, #90accc 40%, #d0dce8 48%,
        #90accc 52%, #6880a0 60%, #4f6a7f 68%, #677f95 76%, #8599ad 84%, #a3b1c6 92%, #a3b1c6 100%
      );
    }
    .nfx.nfx-aurora {
      background-image: linear-gradient(90deg,
        #41ffd1 0%, #34eed0 8%, #2ed8d8 16%, #28c8e8 24%, #50d8ff 32%, #80b8ff 40%, #a898ff 48%,
        #80b8ff 52%, #50d8ff 60%, #28c8e8 68%, #2ed8d8 76%, #34eed0 84%, #41ffd1 92%, #41ffd1 100%
      );
    }
    .nfx.nfx-arcane {
      background-image: linear-gradient(90deg,
        #00e0ff 0%, #18baff 8%, #3098ff 16%, #5070ff 24%, #7848ff 32%, #a058ff 40%, #c898ff 48%,
        #a058ff 52%, #7848ff 60%, #5070ff 68%, #3098ff 76%, #18baff 84%, #00e0ff 92%, #00e0ff 100%
      );
    }
    .nfx.nfx-voidlight {
      background-image: linear-gradient(90deg,
        #0b0014 0%, #1e0038 6%, #350060 12%, #4d0088 18%, #7020b8 24%, #9540e0 30%, #b870ff 36%, #8080ff 42%, #30c8ff 48%,
        #8080ff 52%, #b870ff 58%, #9540e0 64%, #7020b8 70%, #4d0088 76%, #350060 82%, #1e0038 88%, #0b0014 94%, #0b0014 100%
      );
      background-size: 240% 240%;
      animation: nfx-flow-large 4s linear infinite;
    }
    .nfx.nfx-sunset {
      background-image: linear-gradient(90deg,
        #ff9966 0%, #ff8855 8%, #ff7748 16%, #ff6860 24%, #ff9088 32%, #ffb8a8 40%, #ffd8c0 48%,
        #ffb8a8 52%, #ff9088 60%, #ff6860 68%, #ff7748 76%, #ff8855 84%, #ff9966 92%, #ff9966 100%
      );
    }
    .nfx.nfx-neonwire {
      background-image: linear-gradient(90deg,
        #00ffd5 0%, #00e8c8 6%, #00c8ff 12%, #2098ff 18%, #5070ff 24%, #8050ff 30%, #c840ff 36%, #ff40d8 42%, #ff60b8 48%,
        #ff40d8 52%, #c840ff 58%, #8050ff 64%, #5070ff 70%, #2098ff 76%, #00c8ff 82%, #00e8c8 88%, #00ffd5 94%, #00ffd5 100%
      );
      background-size: 240% 240%;
      animation: nfx-flow-large 4s linear infinite;
    }
    .nfx.nfx-toxin {
      background-image: linear-gradient(90deg,
        #c6ff00 0%, #b5ff18 8%, #98ff30 16%, #70ff55 24%, #68ff70 32%, #88ff80 40%, #d8ffa8 48%,
        #88ff80 52%, #68ff70 60%, #70ff55 68%, #98ff30 76%, #b5ff18 84%, #c6ff00 92%, #c6ff00 100%
      );
    }

    .nfx.nfx-platinum {
      background-image:
        linear-gradient(90deg, #eef2f6 0%, #d8dfe8 10%, #c0cdd8 20%, #b0bfce 30%, #c8d5e0 40%, #e0e8f0 50%, #d8dfe8 60%, #c0cdd8 70%, #b0bfce 80%, #d8dfe8 90%, #eef2f6 100%),
        linear-gradient(100deg, rgba(255,255,255,0) 20%, rgba(255,255,255,.60) 25%, rgba(255,255,255,0) 30%, rgba(255,255,255,0) 70%, rgba(255,255,255,.60) 75%, rgba(255,255,255,0) 80%);
      background-size: 180% 180%, 360% 360%;
      text-shadow:
        0 1px 0 rgba(255,255,255,.55),
        0 0 8px rgba(175,190,210,.25),
        0 0 22px rgba(140,155,175,.15);
      animation: nfx-flow-platinum 5s linear infinite;
    }

    /* GM Aegis (multi-layer with tiling) */
    .nfx.nfx-gm-aegis {
      background-image:
        linear-gradient(90deg, #0b1530 0%, #12288a 12%, #18a0f8 25%, #15c8ff 37%, #12288a 50%, #0b1530 50%, #12288a 62%, #18a0f8 75%, #15c8ff 87%, #0b1530 100%),
        linear-gradient(115deg, rgba(255,208,96,0) 20%, rgba(255,208,96,0.65) 25%, rgba(255,208,96,0) 30%, rgba(255,208,96,0) 70%, rgba(255,208,96,0.65) 75%, rgba(255,208,96,0) 80%),
        linear-gradient(70deg, rgba(0,180,255,0) 20%, rgba(0,180,255,.75) 25%, rgba(0,180,255,0) 30%, rgba(0,180,255,0) 70%, rgba(0,180,255,.75) 75%, rgba(0,180,255,0) 80%);
      background-size: 250% 250%, 320% 320%, 280% 280%;
      text-shadow:
        0 0 18px rgba(0,160,255,.25),
        0 0 36px rgba(0,80,255,.18),
        0 1px 0 rgba(255,255,255,.35);
      animation: nfx-flow-aegis 5s linear infinite;
    }

    /* Rainbow */
    .nfx.nfx-rainbow {
      background-image: linear-gradient(
        90deg,
        #ff0066 0%,
        #ff2a66 5%,
        #ff7a00 12.5%,
        #ffcc00 20%,
        #33cc33 27.5%,
        #00c2ff 35%,
        #7a66ff 42.5%,
        #ff00cc 47.5%,
        #ff0066 50%,
        #ff0066 50%,
        #ff2a66 55%,
        #ff7a00 62.5%,
        #ffcc00 70%,
        #33cc33 77.5%,
        #00c2ff 85%,
        #7a66ff 92.5%,
        #ff00cc 97.5%,
        #ff0066 100%
      );
      background-size: 200% 100%;
      animation: nfx-flow-rainbow 2.5s linear infinite;
    }

    /* Keyframe animations for continuous flow */
    @keyframes nfx-flow-large {
      0% { background-position: 0% 0%; }
      100% { background-position: 100% 0%; }
    }

    @keyframes nfx-flow-platinum {
      0% { background-position: 0% 0%, 0% 0%; }
      100% { background-position: 100% 0%, 100% 0%; }
    }

    @keyframes nfx-flow-aegis {
      0% { background-position: 0% 0%, 0% 0%, 0% 0%; }
      100% { background-position: 100% 0%, 100% 0%, 100% 0%; }
    }

    @keyframes nfx-flow-rainbow {
      0% { background-position: 0% 0%; }
      50% { background-position: 100% 0%; }
      100% { background-position: 200% 0%; }
    }

    @media (prefers-reduced-motion: reduce) {
      .nfx { animation: none !important; }
    }
    </style>';
  }
}