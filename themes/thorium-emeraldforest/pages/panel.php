<?php
// Character panel page - displays user characters, account info, and password change form

$APP_ROOT = dirname(__DIR__, 3);
$need = [
  $APP_ROOT . '/app/auth.php',
  $APP_ROOT . '/app/points_repo.php',
  $APP_ROOT . '/app/characters_repo.php',
  $APP_ROOT . '/app/realms_repo.php',
];
foreach ($need as $f) {
  if (!is_file($f)) { http_response_code(500); echo "Missing: ".htmlspecialchars($f); exit; }
}
require_once $need[0];
require_once $need[1];
require_once $need[2];
require_once $need[3];

$u = auth_user();
if (!$u) { redirect(base_url('login')); }

$okMsg = ''; $errMsg = '';

// visible realms (hide admin-only for non-admins)
    /** Process and return array data. */
function panel_visible_realms(array $user): array {
  $all = realms_all();
  $min = (int)($GLOBALS['config']['admin_min_security_level'] ?? 3);
  $level = (int)($user['gmlevel'] ?? $user['security'] ?? 0);
  $isAdmin = !empty($user['is_admin']) || $level >= $min;
  $adminOnlyIds = array_map('intval', $GLOBALS['config']['realms']['admin_only_ids'] ?? []);
  if ($isAdmin || !$adminOnlyIds) return $all;
  return array_values(array_filter($all, static function ($r) use ($adminOnlyIds) {
    $id = (int)($r['id'] ?? 0);
    return $id > 0 && !in_array($id, $adminOnlyIds, true);
  }));
}

    /** Process and return array data. */
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

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $errMsg = 'Invalid CSRF token.';
  } elseif ($action === 'change_password') {
    global $pdo, $authPdo, $config;
    if (!$pdo || !$authPdo) { $errMsg = 'Database not available.'; }
    else {
      $curr = $_POST['current_password'] ?? '';
      $p1   = $_POST['new_password'] ?? '';
      $p2   = $_POST['new_password2'] ?? '';
      if ($p1 !== $p2)         $errMsg = 'New passwords do not match.';
      elseif (strlen($p1) < 6) $errMsg = 'New password must be at least 6 characters.';
      else {
        try {
          change_password_logged_in($config, $pdo, $authPdo, (int)$u['id'], $u['username'], $curr, $p1);
          $okMsg = 'Password updated successfully.';
        } catch (Throwable $e) { $errMsg = $e->getMessage(); }
      }
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

// Balances
$balances = points_get_site($pdo, (int)$u['id']);

$siteEmail = null;
try {
  if (isset($pdo)) {
    $st = $pdo->prepare('SELECT email FROM accounts WHERE id = :id LIMIT 1');
    $st->execute([':id' => (int)$u['id']]);
    $siteEmail = $st->fetchColumn() ?: null;
  }
} catch (Throwable $e) {
  // leave $siteEmail as null if anything fails
}

// Names/colors
$CLASS_NAMES = [1=>'Warrior',2=>'Paladin',3=>'Hunter',4=>'Rogue',5=>'Priest',6=>'Death Knight',7=>'Shaman',8=>'Mage',9=>'Warlock',10=>'Monk',11=>'Druid',12=>'Demon Hunter',13=>'Evoker'];
$CLASS_COLORS = [1=>'#C79C6E',2=>'#F58CBA',3=>'#ABD473',4=>'#FFF569',5=>'#FFFFFF',6=>'#C41F3B',7=>'#0070DE',8=>'#69CCF0',9=>'#9482C9',10=>'#00FF96',11=>'#FF7D0A',12=>'#A330C9',13=>'#33937F'];
$RACE_NAMES = [1=>'Human',2=>'Orc',3=>'Dwarf',4=>'Night Elf',5=>'Undead',6=>'Tauren',7=>'Gnome',8=>'Troll',9=>'Goblin',10=>'Blood Elf',11=>'Draenei',22=>'Worgen',24=>'Pandaren'];
    /** Handle race_faction operation. */
function race_faction(int $raceId): ?string {
  if (in_array($raceId,[1,3,4,7,11,22],true)) return 'Alliance';
  if (in_array($raceId,[2,5,6,8,9,10],true)) return 'Horde';
  return null;
}

// Visible realms + header label
$visibleRealms = panel_visible_realms($u);
if (!$visibleRealms) {
  $realmLabel = $config['char_db']['name'] ?? '—';
} else {
  $names = array_map(static fn($r) => (string)($r['name'] ?? 'Realm'), $visibleRealms);
  $realmLabel = (count($names) <= 3) ? implode(', ', $names)
                                     : implode(', ', array_slice($names,0,2)).' +'.(count($names)-2);
}
?>
<section class="container px-4 pt-16 md:pt-36 pb-24 md:pb-40">
  <div class="relative rough-card overflow-hidden p-0 shine">
    <div class="absolute inset-y-0 left-0 w-1.5 bg-gradient-to-b from-emerald-400/80 to-emerald-500/60"></div>
    <div class="p-6 md:p-8">
      <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
          <p class="kicker">Welcome back</p>
          <h1 class="h-display text-3xl md:text-4xl font-extrabold">Character Panel</h1>
          <p class="mt-2 muted">Greetings, <span class="text-brand-400"><?= e($u['username']) ?></span>.</p>
        </div>
        <div class="flex items-center gap-2">
          <a href="<?= e(base_url('news')) ?>" class="btn-ghost">Latest News</a>
          <?php if (!empty($u['is_admin'])): ?>
            <a href="<?= e(base_url('admin')) ?>" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold
               bg-gradient-to-b from-emerald-400/90 to-lime-500/90 text-emerald-950
               ring-2 ring-emerald-400/40 hover:brightness-105 transition">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" class="-ml-0.5"><path d="M12 2l7 3v6c0 5-3.1 9.7-7 11-3.9-1.3-7-6-7-11V5l7-3zM7 8v3c0 3.7 2.3 7.7 5 9 2.7-1.3 5-5.3 5-9V8l-5-2-5 2z"/></svg>
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
            <div class="mt-1 text-2xl font-extrabold"><?= !empty($u['is_admin']) ? 'GM' : 'Player' ?></div>
          </div>
          <span class="text-[11px] px-2 py-1 rounded bg-white/5 border border-white/10">Realms: <?= e($realmLabel) ?></span>
        </div>
      </div>
    </div>
  </div>

  <?php if ($okMsg): ?>
    <div class="mt-4 rough-card p-3 text-sm text-emerald-300 border-emerald-500/30"><?= e($okMsg) ?></div>
  <?php elseif ($errMsg): ?>
    <div class="mt-4 rough-card p-3 text-sm text-red-300 border-red-500/30"><?= e($errMsg) ?></div>
  <?php endif; ?>

  <!-- Two-column layout: LEFT (2/3) + RIGHT (1/3) from md: -->
  <div class="mt-6 grid gap-6 md:grid-cols-3">
    <!-- LEFT COLUMN -->
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

      <!-- Account Overview (stacked fields) -->
<div class="rough-card p-6">
  <h3 class="font-semibold">Account Overview</h3>
  <div class="mt-4 space-y-4">
    <div>
      <div class="text-xs uppercase opacity-60">Username</div>
      <div class="mt-1 font-semibold break-all"><?= e($u['username']) ?></div>
    </div>
    <div>
      <div class="text-xs uppercase opacity-60">Email</div>
      <div class="mt-1 font-semibold break-words"><?= e($siteEmail ?? '—') ?></div>
    </div>
    <div>
      <div class="text-xs uppercase opacity-60">Role</div>
      <div class="mt-1 font-semibold"><?= !empty($u['is_admin']) ? 'Game Master' : 'Player' ?></div>
    </div>
  </div>
</div>
    </div>

    <!-- RIGHT COLUMN -->
    <aside class="space-y-6">
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
        <p class="mt-3 text-[12px] opacity-60">Forgot your password? <a class="text-amber-300 hover:text-ember-500" href="<?= e(base_url('forgot')) ?>">Reset via email</a>.</p>
      </div>

      <div class="rough-card p-6">
        <h3 class="font-semibold">Account Settings</h3>
        <p class="mt-1 text-sm opacity-70">Email/locale, 2FA (TOTP), and more will live here.</p>
      </div>
    </aside>
  </div>
</section>
