<?php
/**
 * partials/modules/discord.php
 * Module template - renders the discord module component
 */

// partials/modules/discord.php
require_once APP_ROOT . '/discord_repo.php';

$widget     = discord_widget_fetch(60);
$data       = $widget['data'] ?? null;
$ok         = $widget['ok'] ?? false;

$name       = $data['name'] ?? 'Discord';
$online     = $data ? discord_online_count_from($data) : null;
$invite     = discord_invite_url($data) ?: '#';
$gid        = discord_guild_id();

// ↓↓↓ replace these two lines ↓↓↓
$conf       = discord_cfg();
$showWidget = !empty($conf['show_widget']);
$wTheme     = strtolower((string)($conf['widget_theme'] ?? 'dark'));
if ($wTheme !== 'light' && $wTheme !== 'dark') $wTheme = 'dark';
// ↑↑↑ replace these two lines ↑↑↑

// allow toggling sandbox
$useSandbox = !empty($conf['widget_sandbox']);

// Avatars (cap at 18)
$avatars = [];
if (!empty($data['members']) && is_array($data['members'])) {
  foreach ($data['members'] as $m) {
    if (!empty($m['avatar_url'])) {
      $avatars[] = ['url'=>$m['avatar_url'], 'name'=>$m['username'] ?? ''];
      if (count($avatars) >= 18) break;
    }
  }
}
?>
<section class="py-16 relative animate-on-scroll">
  <div class="container max-w-6xl mx-auto px-6">
    <div class="text-center mb-8">
      <p class="kicker">Community</p>
      <h2 class="h-display text-3xl font-bold">Join us on Discord</h2>
    </div>

    <div class="rough-card p-6 md:p-8">
      <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-6">
        <div class="flex items-center gap-4">
          <div class="w-12 h-12 rounded-xl bg-white/5 ring-1 ring-white/10 flex items-center justify-center">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="currentColor" class="opacity-90">
              <path d="M20.317 4.369A19.791 19.791 0 0 0 16.558 3c-.2.352-.43.83-.589 1.205a18.273 18.273 0 0 0-4.938 0A9.042 9.042 0 0 0 10.442 3a19.736 19.736 0 0 0-3.758 1.369C3.3 8.266 2.6 11.996 2.815 15.676c1.59 1.168 3.13 1.873 4.643 2.345.373-.51.705-1.053.989-1.628-.549-.21-1.074-.468-1.565-.765.131-.095.26-.195.384-.299 3.01 1.41 6.268 1.41 9.251 0 .125.104.254.204.384.3-.492.296-1.017.553-1.566.764.284.575.616.628 1.513-.472 3.053-1.177 4.643-2.345.38-6.183-1.019-9.873-3.623-11.307Z"/>
            </svg>
          </div>
          <div>
            <div class="h-display text-xl font-bold"><?= e($name) ?></div>
            <div class="text-sm text-neutral-400">
              <?php if ($online !== null): ?>
                <span class="inline-flex items-center gap-1">
                  <span class="w-2 h-2 rounded-full bg-emerald-400"></span>
                  <?= (int)$online ?> online
                </span>
              <?php else: ?>
                Widget offline
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="flex items-center gap-3">
          <?php if ($online !== null && $avatars): ?>
            <div class="hidden sm:flex -space-x-2">
              <?php foreach ($avatars as $a): ?>
                <img src="<?= e($a['url']) ?>" alt="<?= e($a['name']) ?>"
                     class="w-8 h-8 rounded-full ring-2 ring-bark-900/80 bg-bark-900 object-cover">
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <a href="<?= e($invite) ?>" class="btn-warm px-5 py-2.5" target="_blank" rel="noopener">Join Discord</a>
        </div>
      </div>

      <?php if ($showWidget && $gid): ?>
        <div class="mt-6 rounded-xl overflow-hidden border border-white/10 bg-black/20">
          <iframe
            src="https://discord.com/widget?id=<?= e($gid) ?>&theme=<?= e($wTheme) ?>"
            width="100%" height="320" frameborder="0"
            <?php if ($useSandbox): ?>
              sandbox="allow-popups allow-popups-to-escape-sandbox allow-same-origin allow-scripts"
            <?php endif; ?>
            allow="clipboard-write; fullscreen"
            referrerpolicy="no-referrer-when-downgrade">
          </iframe>
        </div>

      <?php else: ?>
        <div class="mt-6 rounded-xl border border-white/10 p-4 bg-white/5 text-sm text-neutral-300">
          <p class="mb-1">The live Discord widget isn’t enabled.</p>
          <ul class="list-disc list-inside text-neutral-400">
            <li>Set <code>['discord']['guild_id']</code> and <code>['discord']['show_widget'] = true</code> in <code>app/config.php</code>.</li>
            <li>In Discord: <b>Server Settings → Widget</b> → enable “Server Widget” and pick an invite channel.</li>
          </ul>
        </div>
      <?php endif; ?>

      <?php if (!$ok): ?>
        <p class="mt-3 text-sm text-neutral-400">
          If the iframe shows “This content is blocked”, check: widget enabled, server not age-restricted,
          no ad-blockers for <code>discord.com</code>, and your CSP allows <code>frame-src https://discord.com</code>.
          You can also set <code>['discord']['widget_sandbox'] = false</code> (current: <?= $useSandbox ? 'true' : 'false' ?>).
        </p>
      <?php endif; ?>
    </div>
  </div>
</section>
