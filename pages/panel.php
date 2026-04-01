<?php
/**
 * pages/panel.php - Nickname mgmt + RBAC (+ optional Name Effects)
 * Emoji policy: Staff OR VIP >= 4 can use emoji in their nickname. Everyone can change nicknames.
 */
declare(strict_types=1);

// Resolve /app paths (this file lives in /htdocs/pages)
$APP_ROOT = __DIR__ . '/../app';

$need = [
  $APP_ROOT . '/auth.php',
  $APP_ROOT . '/points_repo.php',
  $APP_ROOT . '/characters_repo.php',
  $APP_ROOT . '/realms_repo.php',
  $APP_ROOT . '/nickname_helpers.php',
];
foreach ($need as $f) {
  if (!is_file($f)) {
    http_response_code(500);
    echo "Missing: " . htmlspecialchars($f);
    exit;
  }
  require_once $f;
}

/* --- Optional Name Effects (safe fallbacks if file missing) --- */
$NFX_FILE = $APP_ROOT . '/name_effects.php';
$HAS_NFX  = is_file($NFX_FILE);
if ($HAS_NFX) {
  require_once $NFX_FILE; // nfx_all, nfx_user_unlocks, nfx_active_code, nfx_usable_for_user, nfx_set_active_guarded, etc.
  // Ensure animated CSS is present
  if (function_exists('nfx_print_styles_once')) {
    nfx_print_styles_once();
  }
}

// Session + helpers
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// Auth gate
$loginUrl = function_exists('base_url') ? base_url('login') : '/login';
$redirect = function(string $to){ header('Location: ' . $to); exit; };
$u = auth_user();
if (!$u) { $redirect($loginUrl); }

/**
 * 🔒 Force-refresh RBAC + is_admin live from auth DB for THIS request,
 *     so stale session data can’t leak staff UI bits.
 */
global $authPdo, $config, $pdo;
if ($authPdo instanceof PDO) {
  $minAdmin = (int)($config['admin_min_permission_id'] ?? 191);
  if (function_exists('auth_refresh_session_rbac')) {
    auth_refresh_session_rbac($authPdo, $minAdmin);
  } else {
    $liveRbac = auth_get_rbac_info($authPdo, (int)$u['id']);
    $_SESSION['user']['rbac']     = $liveRbac;
    $_SESSION['user']['is_admin'] = auth_is_admin($authPdo, (int)$u['id'], $minAdmin);
  }
  $u = auth_user(); // reload after refresh
}

// Messages
$okMsg = ''; $errMsg = '';

/**
 * Determine if the user is staff (GM or above).
 */
function panel_is_staff(array $user): bool {
  if (empty($user['rbac']) && isset($GLOBALS['authPdo']) && $GLOBALS['authPdo'] instanceof PDO) {
    $rbac = auth_get_rbac_info($GLOBALS['authPdo'], (int)($user['id'] ?? 0));
    $user['rbac'] = $rbac;
  }

  if (!empty($user['rbac'])) {
    if (!empty($user['rbac']['is_admin'])) return true;
    if (!empty($user['rbac']['is_gm']))    return true;

    $minPerm = (int)($GLOBALS['config']['admin_min_permission_id'] ?? 191);
    $perm    = (int)($user['rbac']['permission_id'] ?? 0);
    if ($perm >= $minPerm) return true;
  }

  if (!empty($user['is_admin'])) return true;
  $level = (int)($user['gmlevel'] ?? $user['security'] ?? 0);
  return $level > 0;
}

/** VIP from DB: Check auth.account_access.SpecialRank first, then fall back to donations. */
function panel_vip_from_db(int $uid): int {
  $authPdo = $GLOBALS['authPdo'] ?? null;
  $pdo = $GLOBALS['pdo'] ?? null;
  if ($uid <= 0) return 0;

  // First, try to get SpecialRank from auth.account_access
  if ($authPdo instanceof PDO) {
    try {
      $st = $authPdo->prepare("SELECT `SpecialRank` FROM `account_access` WHERE `AccountID` = ? LIMIT 1");
      if ($st->execute([$uid])) {
        $specialRank = $st->fetchColumn();
        if ($specialRank !== false && $specialRank !== null && (int)$specialRank > 0) {
          return (int)$specialRank;
        }
      }
    } catch (Throwable $__) {}
  }

  // Fall back to donation-based calculation if SpecialRank is 0 or not found
  if (!($pdo instanceof PDO)) return 0;
  $total = 0.0;

  try {
    $st = $pdo->prepare("SELECT total_spent FROM thorium_website.accounts WHERE id=? LIMIT 1");
    if ($st->execute([$uid])) {
      $v = $st->fetchColumn();
      if ($v !== false && $v !== null) $total = max($total, (float)$v);
    }
  } catch (Throwable $__) {}

  if ($total <= 0) {
    try {
      $st = $pdo->prepare("SELECT total_spent FROM accounts WHERE id=? LIMIT 1");
      if ($st->execute([$uid])) {
        $v = $st->fetchColumn();
        if ($v !== false && $v !== null) $total = max($total, (float)$v);
      }
    } catch (Throwable $__) {}
  }

  // Match your actual VIP tiers
  if ($total >= 200) return 8;
  if ($total >= 160) return 7;
  if ($total >= 120) return 6;
  if ($total >= 80)  return 5;
  if ($total >= 60)  return 4;
  if ($total >= 40)  return 3;
  if ($total >= 20)  return 2;
  if ($total >= 10)  return 1;
  return 0;
}

/**
 * Visible realms:
 *  - Non-staff: ONLY realm ID 1 (and not in admin_only_ids).
 *  - Staff: all realms.
 *  - Respects config['realms']['admin_only_ids'] (CSV from .env).
 */
function panel_visible_realms(array $user): array {
  $all = realms_all();
  if (!$all) return [];

  $isStaff = panel_is_staff($user);
  $adminOnlyIds = array_map('intval', $GLOBALS['config']['realms']['admin_only_ids'] ?? []);
  $defaultRealm = (int)($GLOBALS['config']['realms']['default_realm'] ?? 1);

  if ($isStaff) return $all;

  $playerRealms = array_values(array_filter($all, static function ($realm) use ($defaultRealm) {
    return (int)($realm['id'] ?? 0) === $defaultRealm;
  }));

  if ($adminOnlyIds && $playerRealms) {
    $playerRealms = array_values(array_filter($playerRealms, static function ($r) use ($adminOnlyIds) {
      return !in_array((int)($r['id'] ?? 0), $adminOnlyIds, true);
    }));
  }

  return $playerRealms;
}

function panel_chars_for_account_on_realm(int $realmId, int $accountId): array {
  $pdo = realm_char_pdo($realmId);
  if (!$pdo) return [];
  $sql = "SELECT guid, name, race, class, level, online
          FROM characters
          WHERE account = :acc
          ORDER BY online DESC, level DESC, name ASC";
  $st = $pdo->prepare($sql);
  $st->execute([':acc' => $accountId]);
  return $st->fetchAll() ?: [];
}

/* ---------- VIP + Emoji flag ---------- */
$vipLevel   = panel_vip_from_db((int)$u['id']);
$allowEmoji = panel_is_staff($u) || $vipLevel >= 4;

/* ---------- Handle POST actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  if (!csrf_check($_POST['csrf'] ?? '')) {
    $errMsg = 'Invalid CSRF token.';
  } elseif ($action === 'change_password') {
    global $pdo, $authPdo, $config;
    if (!$pdo || !$authPdo) {
      $errMsg = 'Database not available.';
    } else {
      $curr = $_POST['current_password'] ?? '';
      $p1   = $_POST['new_password'] ?? '';
      $p2   = $_POST['new_password2'] ?? '';
      if ($p1 !== $p2)         $errMsg = 'New passwords do not match.';
      elseif (strlen($p1) < 6) $errMsg = 'New password must be at least 6 characters.';
      else {
        try {
          change_password_logged_in($config, $pdo, $authPdo, (int)$u['id'], $u['username'], $curr, $p1);
          $okMsg = 'Password updated successfully.';
        } catch (Throwable $e) {
          $errMsg = $e->getMessage();
        }
      }
    }
  } elseif ($action === 'change_nickname') {
    // Everyone may attempt to change their nickname (rate limiting still applies).
    global $pdo;
    if (!$pdo) {
      $errMsg = 'Database not available.';
    } else {
      $new_nickname = trim($_POST['new_nickname'] ?? '');
      if ($new_nickname === '') {
        $errMsg = 'Nickname cannot be empty.';
      } else {
        $result = change_user_nickname($pdo, (int)$u['id'], $new_nickname, null, ['allow_emoji' => $allowEmoji]);

        if (!empty($result['success'])) {
          $okMsg = $result['message'] ?? 'Nickname updated.';
          if (function_exists('auth_refresh_user_nickname')) {
            auth_refresh_user_nickname($pdo);
          }
          $u = auth_user(); // Reload user data
        } else {
          $errMsg = !empty($result['errors']) ? implode(' ', (array)$result['errors']) : 'Failed to update nickname.';
        }
      }
    }
  } elseif ($action === 'generate_new_nickname') {
    global $pdo;
    if (!$pdo) {
      $errMsg = 'Database not available.';
    } else {
      $rate_check = can_change_nickname($pdo, (int)$u['id']);
      if (!$rate_check['can_change']) {
        $errMsg = $rate_check['reason'];
      } else {
        $new_nickname = generate_unique_nickname($pdo);
        $result = change_user_nickname($pdo, (int)$u['id'], $new_nickname, null, ['allow_emoji' => false]);
        if (!empty($result['success'])) {
          $okMsg = 'New nickname generated: ' . $new_nickname;
          if (function_exists('auth_refresh_user_nickname')) {
            auth_refresh_user_nickname($pdo);
          }
          $u = auth_user(); // Reload user data
        } else {
          $errMsg = 'Failed to generate new nickname.';
        }
      }
    }
  } elseif ($action === 'set_name_effect') {
    if ($HAS_NFX) {
      $code = trim($_POST['effect_code'] ?? '');
      $wantIncludeEmoji = isset($_POST['include_emoji']) && $_POST['include_emoji'] ? true : false;

      // Save emoji-preference first (works even if no effect is chosen)
      if (function_exists('nfx_set_include_emoji')) {
        nfx_set_include_emoji($pdo, (int)$u['id'], $wantIncludeEmoji);
      }

      $ctx  = ['vip' => (int)$vipLevel, 'staff' => panel_is_staff($u)];
      $res  = nfx_set_active_guarded($pdo, (int)$u['id'], $code === 'none' ? null : $code, $ctx);

      if (!empty($res['ok'])) $okMsg = ($res['message'] ?? 'Name effect updated.') . ' Preferences saved.';
      else $errMsg = $res['error'] ?? 'Failed to update name effect.';
    } else {
      $errMsg = 'Name effects are not enabled on this site.';
    }
  } elseif ($action === 'unstuck') {
    $guid    = (int)($_POST['guid'] ?? 0);
    $realmId = (int)($_POST['realm_id'] ?? 0);
    if ($guid <= 0 || $realmId <= 0) {
      $errMsg = 'Invalid character or realm.';
    } else {
      if (function_exists('characters_unstuck_on_realm')) {
        [$ok, $msg] = characters_unstuck_on_realm((int)$u['id'], $guid, $realmId);
      } else {
        [$ok, $msg] = characters_unstuck((int)$u['id'], $guid);
      }
      if ($ok) $okMsg = $msg; else $errMsg = $msg;
    }
  }
}

// Use smart points functions that prefer auth database
$balances = points_get_main((int)$u['id']);

// Get nickname information
$nickname_info = get_user_nickname_info($pdo, (int)$u['id']);
$rate_check = can_change_nickname($pdo, (int)$u['id']);

// (Optional) Name effects data for UI
$activeEffect = null;
$effects = $unlocks = $usable = $locked = [];
$includeEmojiPref = true; // default
if ($HAS_NFX) {
  try { $activeEffect = nfx_active_code($pdo, (int)$u['id']); } catch (Throwable $__) { $activeEffect = null; }

  try {
    if (function_exists('nfx_include_emoji')) {
      $includeEmojiPref = nfx_include_emoji($pdo, (int)$u['id']);
    }
  } catch (Throwable $__) {}

  try {
    $isStaff  = panel_is_staff($u);
    $vipInt   = (int)$vipLevel;

    if (function_exists('nfx_partition_for_user')) {
      $part = nfx_partition_for_user($pdo, (int)$u['id'], $vipInt, $isStaff);
      $usable = $part['usable'];
      // Hide staff-only entries entirely for non-staff
      $locked = $isStaff ? $part['locked']
                         : array_values(array_filter($part['locked'], static fn($fx) => empty($fx['staff_only'])));
    } else {
      // Manual partition if helper isn't present
      $effects = nfx_all($pdo);
      $unlocks = nfx_user_unlocks($pdo, (int)$u['id']);
      foreach ($effects as $fx) {
        $isUsable = nfx_usable_for_user($fx, $vipInt, $isStaff, $unlocks);
        if ($isUsable) { $usable[] = $fx; continue; }
        if (!$isStaff && !empty($fx['staff_only'])) continue; // non-staff never see staff-only
        $locked[] = $fx;
      }
    }
  } catch (Throwable $__) {
    $effects = $unlocks = $usable = $locked = [];
  }
}

// Site email
$siteEmail = null;
try {
  if (isset($pdo)) {
    $st = $pdo->prepare('SELECT email FROM accounts WHERE id = :id LIMIT 1');
    $st->execute([':id' => (int)$u['id']]);
    $siteEmail = $st->fetchColumn() ?: null;
  }
} catch (Throwable $e) {}

// Determine points source for display
$pointsSource = 'Unknown';
try {
  if ($authPdo && points_auth_has_columns($authPdo)) {
    $pointsSource = 'Auth Database';
  } elseif ($pdo && points_site_has_columns($pdo)) {
    $pointsSource = 'Site Database';
  }
} catch (Throwable $e) {}

// Class/Race constants
$CLASS_NAMES  = [1=>'Warrior',2=>'Paladin',3=>'Hunter',4=>'Rogue',5=>'Priest',6=>'Death Knight',7=>'Shaman',8=>'Mage',9=>'Warlock',10=>'Monk',11=>'Druid',12=>'Demon Hunter',13=>'Evoker'];
$CLASS_COLORS = [1=>'#C79C6E',2=>'#F58CBA',3=>'#ABD473',4=>'#FFF569',5=>'#FFFFFF',6=>'#C41F3B',7=>'#0070DE',8=>'#69CCF0',9=>'#9482C9',10=>'#00FF96',11=>'#FF7D0A',12=>'#A330C9',13=>'#33937F'];
$RACE_NAMES   = [1=>'Human',2=>'Orc',3=>'Dwarf',4=>'Night Elf',5=>'Undead',6=>'Tauren',7=>'Gnome',8=>'Troll',9=>'Goblin',10=>'Blood Elf',11=>'Draenei',22=>'Worgen',24=>'Pandaren'];

function race_faction(int $raceId): ?string {
  if (in_array($raceId,[1,3,4,7,11,22],true)) return 'Alliance';
  if (in_array($raceId,[2,5,6,8,9,10],true)) return 'Horde';
  return null;
}

// Visible realms + label
$visibleRealms = panel_visible_realms($u);
if (!$visibleRealms) {
  $realmLabel = $GLOBALS['config']['char_db']['name'] ?? '—';
} else {
  $names = array_map(static fn($r) => (string)($r['name'] ?? 'Realm'), $visibleRealms);
  $realmLabel = (count($names) <= 3) ? implode(', ', $names)
                                     : implode(', ', array_slice($names,0,2)).' +'.(count($names)-2);
}

// Get user role name for display (RBAC system)
$userRole = 'Player';
$userStatus = 'Player';
if (isset($u['rbac']['role_name'])) {
  $userRole = $u['rbac']['role_name'];
  $userStatus = $u['rbac']['role_name'];
} elseif (!empty($u['is_admin'])) {
  $userStatus = 'Admin';
  $userRole = 'Game Master';
}

// URLs
$toNews  = function_exists('base_url') ? base_url('news')  : '/news';
$toAdmin = function_exists('base_url') ? base_url('admin') : '/admin';
$toForgot= function_exists('base_url') ? base_url('forgot'): '/forgot';
$toStore = function_exists('base_url') ? base_url('store') : '/store';
?>
<section class="container px-4 pt-16 md:pt-36 pb-24 md:pb-40">
  <div class="relative rough-card overflow-hidden p-0 shine">
    <div class="absolute inset-y-0 left-0 w-1.5 bg-gradient-to-b from-emerald-400/80 to-emerald-500/60"></div>
    <div class="p-6 md:p-8">
      <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
          <p class="kicker">Welcome back</p>
          <h1 class="h-display text-3xl md:text-4xl font-extrabold">Character Panel</h1>
          <p class="mt-2 muted">
            Greetings, <?= get_user_display_name_html($pdo, (int)$u['id']) ?>.
          </p>
        </div>
        <div class="flex items-center gap-2">
          <a href="<?= e($toNews) ?>" class="btn-ghost">Latest News</a>
          <?php if (panel_is_staff($u)): ?>
            <a href="<?= e($toAdmin) ?>" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold
               bg-gradient-to-b from-emerald-400/90 to-lime-500/90 text-emerald-950
               ring-2 ring-emerald-400/40 hover:brightness-105 transition">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" class="-ml-0.5"><path d="M12 2l7 3v6c0 5-3.1 9.7-7 11-3.9-1.3-7-6-7-11V5l7-3z"/></svg>
              Admin Panel
            </a>
          <?php endif; ?>
        </div>
      </div>

      <div class="mt-6 grid gap-4 sm:grid-cols-3">
        <div class="rounded-xl border border-white/10 p-4 bg-white/[0.03]">
          <div class="text-[11px] uppercase tracking-wide text-neutral-300/80">Vote Points</div>
          <div class="mt-1 text-2xl font-extrabold tabular-nums"><?= (int)$balances['vote'] ?></div>
        </div>
        <div class="rounded-xl border border-white/10 p-4 bg-white/[0.03]">
          <div class="text-[11px] uppercase tracking-wide text-neutral-300/80">Donation Points</div>
          <div class="mt-1 text-2xl font-extrabold tabular-nums"><?= (int)$balances['donation'] ?></div>
        </div>
        <div class="rounded-xl border border-white/10 p-4 bg-white/[0.03] flex items-center justify-between">
          <div>
            <div class="text-[11px] uppercase tracking-wide text-neutral-300/80">Status</div>
            <div class="mt-1 text-2xl font-extrabold"><?= e($userStatus) ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <?php if ($okMsg): ?>
    <div class="mt-4 rough-card p-3 text-sm text-emerald-300 border-emerald-500/30"><?= e($okMsg) ?></div>
  <?php elseif ($errMsg): ?>
    <div class="mt-4 rough-card p-3 text-sm text-red-300 border-red-500/30"><?= e($errMsg) ?></div>
  <?php endif; ?>

  <div class="mt-6 grid gap-6 md:grid-cols-3">
    <!-- LEFT -->
    <div class="md:col-span-2 space-y-6">
      <?php if (!$visibleRealms): ?>
        <div class="rough-card p-6">
          <h3 class="font-semibold">Your Characters</h3>
          <p class="mt-2 text-sm text-neutral-400">No visible realms.</p>
        </div>
      <?php else: ?>
        <?php foreach ($visibleRealms as $realm): ?>
          <?php
            $rid   = (int)($realm['id'] ?? 0);
            $rname = trim((string)($realm['name'] ?? ''));
            if ($rname === '') $rname = 'Realm #'.$rid;
            $list  = panel_chars_for_account_on_realm($rid, (int)$u['id']);
          ?>
          <div class="rough-card p-0 overflow-hidden">
            <div class="px-6 pt-5">
              <div class="flex items-center justify-between">
                <h3 class="font-semibold">
                  <span class="inline-block bg-gradient-to-r from-emerald-300 via-lime-300 to-emerald-400 bg-clip-text text-transparent align-baseline [text-shadow:0_0_12px_rgba(34,197,94,.25)]">
                    <?= e($rname) ?>
                  </span>
                  <span class="text-neutral-200/90"> — Your Characters</span>
                </h3>
              </div>
              <p class="text-sm text-neutral-400 mt-1">Use <em>Unstuck</em> to move offline characters to their home bind.</p>
            </div>

            <div class="px-6 pb-6">
              <?php if (!$list): ?>
                <p class="mt-4 text-sm text-neutral-400">No characters on this realm yet.</p>
              <?php else: ?>
                <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                  <?php foreach ($list as $c): ?>
                    <?php
                      $online  = (int)$c['online'] === 1;
                      $lv      = (int)$c['level'];
                      $name    = (string)$c['name'];
                      $guid    = (int)$c['guid'];
                      $raceId  = (int)$c['race'];
                      $classId = (int)$c['class'];
                      $raceName  = $RACE_NAMES[$raceId]  ?? ('Race '.$raceId);
                      $className = $CLASS_NAMES[$classId] ?? ('Class '.$classId);
                      $classHex  = $CLASS_COLORS[$classId] ?? '#E5E7EB';
                      $faction   = race_faction($raceId);
                      $tint = $online ? 'ring-emerald-400/30' : 'ring-white/10';
                      $dot  = $online ? 'bg-emerald-400' : 'bg-neutral-500';
                    ?>
                    <div class="rounded-xl border border-white/10 bg-white/[0.03] p-4 ring-1 <?= $tint ?> tilt group">
                      <div class="flex items-center justify-between gap-3">
                        <div class="font-medium truncate flex items-center gap-2">
                          <span class="inline-block w-2 h-2 rounded-full <?= $dot ?>"></span>
                          <span class="truncate"><?= e($name) ?></span>
                        </div>
                        <span class="text-xs px-2 py-0.5 rounded bg-white/5 border border-white/10">Lv <?= $lv ?></span>
                      </div>
                      <div class="mt-1 text-sm opacity-70">
                        <?= e($raceName) ?> •
                        <span style="color: <?= e($classHex) ?>"><?= e($className) ?></span>
                        <?php if ($faction): ?> • <?= e($faction) ?><?php endif; ?>
                      </div>
                      <div class="mt-3 flex gap-2">
                        <?php if ($online): ?>
                          <button class="btn-ghost opacity-60 cursor-not-allowed" title="Log out in-game to use Unstuck">Unstuck</button>
                        <?php else: ?>
                          <form method="post">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="unstuck">
                            <input type="hidden" name="guid" value="<?= $guid ?>">
                            <input type="hidden" name="realm_id" value="<?= $rid ?>">
                            <button class="btn-ghost">Unstuck</button>
                          </form>
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <!-- Account Overview -->
      <div class="rough-card p-6">
        <h3 class="font-semibold">Account Overview</h3>
        <div class="mt-4 space-y-4">
          <div>
            <div class="text-xs uppercase opacity-60">Username</div>
            <div class="mt-1 font-semibold break-all"><?= e($u['username']) ?></div>
          </div>
          <div>
            <div class="text-xs uppercase opacity-60">Nickname</div>
            <div class="mt-1 font-semibold break-words">
              <?php
                $nickToShow = $nickname_info['nickname'] ?: 'Not set';
                if ($HAS_NFX && $activeEffect && function_exists('nfx_render_html')) {
                  echo nfx_render_html($nickToShow, $activeEffect, $includeEmojiPref);
                } else {
                  echo e($nickToShow);
                }
              ?>
              <?php if ($nickname_info['nickname']): ?>
                <span class="text-xs text-neutral-400 ml-2">(shown in chat & leaderboards)</span>
              <?php endif; ?>
            </div>
          </div>
          <div>
            <div class="text-xs uppercase opacity-60">Email</div>
            <div class="mt-1 font-semibold break-words"><?= e($siteEmail ?? '—') ?></div>
          </div>
          <div>
            <div class="text-xs uppercase opacity-60">Role</div>
            <div class="mt-1 font-semibold"><?= e($userRole) ?></div>
          </div>
          <div>
            <div class="text-xs uppercase opacity-60">VIP Level</div>
            <div class="mt-1 font-semibold">VIP<?= (int)$vipLevel ?></div>
          </div>
        </div>
      </div>
    </div>

    <!-- RIGHT -->
    <aside class="space-y-6">
      <!-- Nickname Management -->
      <div class="rough-card p-6">
        <h3 class="font-semibold">Nickname Settings</h3>
        <p class="mt-1 text-sm opacity-70">Your nickname is displayed in chat, leaderboards, and other public areas.</p>

        <div class="mt-4 space-y-4">
          <div>
            <div class="text-xs uppercase opacity-60">Current Nickname</div>
            <div class="mt-1 font-semibold break-words text-brand-400">
              <?php
                $currNick = $nickname_info['nickname'] ?: $nickname_info['username'];
                if ($HAS_NFX && $activeEffect && function_exists('nfx_render_html')) {
                  echo nfx_render_html($currNick, $activeEffect, $includeEmojiPref);
                } else {
                  echo e($currNick);
                }
              ?>
            </div>
            <?php if ($nickname_info['last_changed']): ?>
              <div class="text-xs text-neutral-400 mt-1">
                Last changed: <?= date('M j, Y', strtotime($nickname_info['last_changed'])) ?>
              </div>
            <?php endif; ?>
          </div>

          <?php if ($rate_check['can_change']): ?>
            <form method="post" class="space-y-3" id="nickname-form">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="change_nickname">
              <div>
                <label class="block text-sm text-neutral-300 mb-1">New Nickname</label>
                <input
                  name="new_nickname"
                  id="new_nickname"
                  type="text"
                  minlength="3"
                  maxlength="12"
                  inputmode="text"
                  autocomplete="off"
                  autocapitalize="off"
                  placeholder=""
                  class="w-full rounded-xl bg-black/30 border border-white/10 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-amber-500"
                  required
                />
                <div id="nn-help" class="text-xs text-neutral-400 mt-1">
                  <?php if ($allowEmoji): ?>
                    3–12 characters. Letters, numbers, underscores, and emoji. No spaces.
                  <?php else: ?>
                    3–12 characters. Letters, numbers, and underscores only. Emoji is reserved for VIP 4+ or staff.
                  <?php endif; ?>
                </div>
                <div id="nn-err" class="hidden text-xs text-amber-300 mt-1"></div>
              </div>
              <button id="nn-submit" class="btn-warm w-full">Change Nickname</button>
            </form>

            <div class="text-center">
              <div class="text-xs text-neutral-400 mb-2">or</div>
              <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="generate_new_nickname">
                <button class="btn-ghost w-full">Generate Random Nickname</button>
              </form>
            </div>
          <?php else: ?>
            <div class="text-sm text-amber-300 bg-amber-500/10 border border-amber-500/20 rounded-xl p-3">
              <?= e($rate_check['reason']) ?>
            </div>
          <?php endif; ?>

          <div class="text-xs text-neutral-400 space-y-1">
            <div>• Nicknames must be unique across all players</div>
            <div>• Inappropriate names will be reset by admins</div>
          </div>
        </div>
      </div>

      <!-- Name Effects (only shown if module file exists) -->
      <?php if ($HAS_NFX): ?>
        <div class="rough-card p-6">
          <h3 class="font-semibold">Name Effect</h3>
          <p class="mt-1 text-sm opacity-70">Flair for your displayed name across the site.</p>

          <form method="post" class="mt-4 space-y-3">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="set_name_effect">

            <div class="grid gap-2 sm:grid-cols-2">
              <!-- None -->
              <label class="rounded-xl border border-white/10 bg-white/5 p-3 flex items-center gap-2 cursor-pointer">
                <input type="radio" name="effect_code" value="none" <?= $activeEffect ? '' : 'checked' ?>>
                <span>None</span>
              </label>

              <?php foreach ($usable as $fx): ?>
                <?php $code = (string)$fx['code']; $label = (string)$fx['label']; ?>
                <label class="rounded-xl border border-white/10 bg-white/5 p-3 flex items-center gap-2 cursor-pointer">
                  <input type="radio" name="effect_code" value="<?= e($code) ?>" <?= $activeEffect === $code ? 'checked' : '' ?>>
                  <span class="nfx nfx-<?= e($code) ?> font-semibold"><?= e($label) ?></span>
                </label>
              <?php endforeach; ?>
            </div>

            <!-- NEW: Include effect on emojis -->
            <label class="mt-2 flex items-center gap-2">
              <input type="checkbox" name="include_emoji" value="1" <?= $includeEmojiPref ? 'checked' : '' ?>>
              <span class="text-sm">Include effect on emojis</span>
              <span class="text-xs text-neutral-400">(uncheck to keep emojis unstyled)</span>
            </label>

            <?php if ($locked): ?>
              <div class="mt-3 text-xs text-neutral-400">
                <div class="opacity-80 mb-1">Locked effects</div>
                <div class="grid gap-2 sm:grid-cols-2">
                  <?php foreach ($locked as $fx): ?>
                    <div class="rounded-xl border border-white/10 bg-white/5 p-3 opacity-60">
                      <div class="font-semibold"><?= e($fx['label']) ?></div>
                      <?php if (!empty($fx['staff_only'])): ?>
                        <div>Staff only</div>
                      <?php elseif (!empty($fx['min_vip'])): ?>
                        <div>Requires VIP<?= (int)$fx['min_vip'] ?>+</div>
                      <?php else: ?>
                        <div>Unlock via promo</div>
                      <?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endif; ?>

            <button class="btn-warm w-full mt-3">Save Effect</button>
          </form>
        </div>
      <?php endif; ?>

      <div class="rough-card p-6">
        <h3 class="font-semibold">Change Password</h3>
        <p class="mt-1 text-sm opacity-70">This updates your website login and in-game SRP credentials.</p>
        <form method="post" class="mt-4 space-y-3">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="change_password">
          <div>
            <label class="block text-sm text-neutral-300 mb-1">Current password</label>
            <input name="current_password" type="password" required class="w-full rounded-xl bg-black/30 border border-white/10 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-amber-500"/>
          </div>
          <div class="grid gap-3 sm:grid-cols-2">
            <div>
              <label class="block text-sm text-neutral-300 mb-1">New password</label>
              <input name="new_password" type="password" minlength="6" required class="w-full rounded-xl bg-black/30 border border-white/10 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-amber-500"/>
            </div>
            <div>
              <label class="block text-sm text-neutral-300 mb-1">Confirm new password</label>
              <input name="new_password2" type="password" minlength="6" required class="w-full rounded-xl bg-black/30 border border-white/10 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-amber-500"/>
            </div>
          </div>
          <button class="btn-warm w-full">Update password</button>
        </form>
        <p class="mt-3 text-[12px] opacity-60">Forgot your password? <a class="text-amber-300 hover:text-ember-500" href="<?= e($toForgot) ?>">Reset via email</a>.</p>
      </div>

      <div class="rough-card p-6">
        <h3 class="font-semibold">Account Settings</h3>
        <p class="mt-1 text-sm opacity-70">Email/locale, 2FA (TOTP), and more will live here.</p>
      </div>
    </aside>
  </div>
</section>

<?php /* --- Nickname front-end validator (emoji optional, no spaces) --- */ ?>
<script>
(function(){
  const input   = document.getElementById('new_nickname');
  const err     = document.getElementById('nn-err');
  const submit  = document.getElementById('nn-submit');
  const allowEmoji = <?= $allowEmoji ? 'true' : 'false' ?>;

  if (!input || !err || !submit) return;

  function showError(msg){
    err.textContent = msg || '';
    err.classList.toggle('hidden', !msg);
    submit.disabled = !!msg;
    submit.classList.toggle('opacity-50', !!msg);
    submit.classList.toggle('cursor-not-allowed', !!msg);
  }

  function hasControlChars(s){
    return /[\u0000-\u001F\u007F]/.test(s);
  }

  function asciiNicknameOK(v){
    // Letters, numbers, underscores only
    return /^[A-Za-z0-9_]+$/.test(v) && !/^_|__$|_$/.test(v) && !/__/.test(v);
  }

  function validate(){
    let v = input.value || '';
    try { v = v.normalize('NFC'); } catch(e){}
    v = v.trim();
    input.value = v;

    if (v.length === 0) { showError('Nickname cannot be empty.'); return; }
    if (v.length < 3)   { showError('Nickname must be at least 3 characters.'); return; }
    if (v.length > 12)  { showError('Nickname must be 12 characters or less.'); return; }
    if (/\s/.test(v))   { showError('Spaces are not allowed. Use "_" instead.'); return; }
    if (hasControlChars(v)) { showError('Invalid control characters.'); return; }

    if (!allowEmoji) {
      if (!asciiNicknameOK(v)) { showError('Use letters, numbers, and underscores only.'); return; }
    }
    showError('');
  }

  input.addEventListener('input', validate);
  input.addEventListener('blur', validate);
  validate();
})();
</script>
