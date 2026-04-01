<?php
/**
 * partials/modules/realms.php
 * Module template - renders the realms module component
 * Updated: never shows realmlist/host publicly (even for admins).
 */
require_once APP_ROOT . '/realms_repo.php';

// Admin detection using RBAC or legacy fallback
$u = $_SESSION['user'] ?? [];
$isAdmin = false;

if ($u) {
    global $authPdo, $config;

    if ($authPdo && function_exists('auth_is_admin')) {
        $minPermissionId = (int)($config['security']['admin_min_permission_id'] ?? 191);
        $isAdmin = auth_is_admin($authPdo, (int)$u['id'], $minPermissionId);
    } else {
        $min  = (int)($config['security']['admin_min_security_level'] ?? 3);
        $level = (int)($u['gmlevel'] ?? $u['security'] ?? 0);
        $isAdmin = !empty($u['is_admin']) || $level >= $min;
    }
}

// Realm visibility rule: players see realm ID 1; admins see all (but we still hide host)
$allRealms = realms_all();
if ($isAdmin) {
    $realms = $allRealms;
} else {
    $realms = array_values(array_filter($allRealms, fn($realm) => (int)($realm['id'] ?? 0) === 1));
}
?>
<section class="py-16 relative animate-on-scroll">
  <div class="container max-w-6xl mx-auto px-6">
    <div class="text-center mb-12">
      <p class="kicker">Live Status</p>
      <h2 class="h-display text-4xl font-bold">Realms</h2>
    </div>

    <?php if (!$realms): ?>
      <div class="rough-card p-6 text-center">
        <p class="muted">No realms available.</p>
        <?php if (!$isAdmin): ?>
          <p class="text-sm text-neutral-400 mt-2">Contact an administrator if you need access to additional realms.</p>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="grid gap-6 lg:grid-cols-3">
        <?php foreach ($realms as $r): ?>
          <?php
            $id    = (int)   ($r['id'] ?? 0);
            $name  = (string)($r['name'] ?? 'Unknown Realm');
            $host  = (string)($r['address'] ?? '127.0.0.1'); // we will NOT display this
            $port  = (int)   ($r['port'] ?? 8085);

            $probe   = realm_probe($host, $port, 0.6);
            $online  = $probe['online'];

            $players = realm_online_count($id);
            $uptimeS = realm_uptime_seconds($id);
            $uptxt   = format_uptime_short($uptimeS);

            $statusText  = $online ? 'Online' : 'Offline';
            $statusPill  = $online
              ? 'bg-emerald-900/25 text-emerald-300 ring-1 ring-emerald-500/30'
              : 'bg-red-900/25 text-red-300 ring-1 ring-red-500/30';

            $stripeGrad  = $online ? 'from-emerald-400/80 to-emerald-500/60' : 'from-red-400/80 to-red-500/60';
            $bigNumColor = $online ? 'text-emerald-400' : 'text-red-400';
            $nameHover   = $online ? 'group-hover:text-emerald-300' : 'group-hover:text-red-300';
            $playersTxt  = ($players === null) ? '—' : (string)$players;

            $dotClass = $online
              ? 'inline-block w-3 h-3 rounded-full bg-emerald-400 animate-pulse'
              : 'inline-block w-3 h-3 rounded-full bg-red-500';
          ?>
          <div class="relative rough-card rough-card-hover p-0 group overflow-hidden">
            <div class="absolute inset-y-0 left-0 w-1.5 bg-gradient-to-b <?= $stripeGrad ?>"></div>
            <div class="p-5 pb-4">
              <div class="flex items-center gap-3">
                <span class="<?= $dotClass ?>"></span>
                <div class="flex-1 min-w-0">
                  <div class="font-bold text-lg truncate transition-colors <?= $nameHover ?>">
                    <?= e($name) ?>
                  </div>
                  <!-- Removed host:port display (even for admins) to avoid exposing realmlist/IP -->
                  <?php /* If you ever want to show something only to admins without leaking IP:
                  <?php if ($isAdmin): ?>
                    <div class="text-xs text-neutral-400 truncate">Launcher-managed connection</div>
                  <?php endif; ?> */ ?>
                </div>
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs <?= $statusPill ?>">
                  <?php if ($online): ?>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" class="opacity-90">
                      <path d="M9 16.2l-3.5-3.6L4 14l5 5 11-11-1.5-1.4z"/>
                    </svg>
                  <?php else: ?>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" class="opacity-90">
                      <path d="M18.3 5.71L12 12.01 5.7 5.7 4.29 7.11 10.59 13.4 4.3 19.7l1.41 1.41 6.29-6.29 6.3 6.3 1.41-1.41-6.29-6.3 6.29-6.29z"/>
                    </svg>
                  <?php endif; ?>
                  <?= e($statusText) ?>
                </span>
              </div>
            </div>
            <div class="px-5 pb-5">
              <div class="grid grid-cols-3 items-end">
                <div class="col-span-2">
                  <div class="text-xs uppercase tracking-wider text-neutral-400 mb-1">Players Online</div>
                  <div class="text-3xl font-extrabold tabular-nums <?= $bigNumColor ?>"><?= e($playersTxt) ?></div>
                </div>
                <div class="col-span-1 justify-self-end text-right">
                  <div class="text-xs uppercase tracking-wider text-neutral-400 mb-1">Uptime</div>
                  <div class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-white/5 ring-1 ring-white/10 text-sm">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" class="opacity-80">
                      <path d="M12 1a11 11 0 1011 11A11.013 11.013 0 0012 1zm1 11H7v-2h4V5h2z"></path>
                    </svg>
                    <span><?= e($uptxt) ?></span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

    <?php endif; ?>
  </div>
</section>
