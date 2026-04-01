<?php
/**
 * themes/thorium-test/pages/how-to.php
 * Theme page override - customizes page template for this theme
 */

// themes/thorium-emeraldforest/pages/how-to.php

// Pull from config (same pattern panel.php relies on)
$config = $GLOBALS['config'] ?? [];

// Optional connect block (you can add it later to config.php)
$connect     = $config['connect'] ?? [];
$realmlist   = $connect['realmlist']     ?? getenv('THORIUM_REALMLIST') ?: 'to a realmlist, lmao';
$client_link = $connect['client_link']   ?? '';
$launcher    = $connect['launcher_link'] ?? '';
$patch_link  = $connect['patch_link']    ?? '';
$addon_pack  = $connect['addon_pack']    ?? '';
$expansion   = $connect['expansion']     ?? '3.3.5a';

// Discord (matches your config.php structure)
$dc                  = $config['discord'] ?? [];
$discord_inv         = $dc['invite_url'] ?? '';
$discord_gid         = $dc['guild_id'] ?? '';
$discord_theme       = in_array(($dc['widget_theme'] ?? 'dark'), ['dark','light'], true) ? $dc['widget_theme'] : 'dark';
$discord_show_widget = !empty($dc['show_widget']);

// -----------------------------
// Downloads (DB) — repo + safe fallback to config
// -----------------------------
$downloads = [];
try {
  global $pdo;
  $APP_ROOT = dirname(__DIR__, 3);

  // Try both conventions: app/repos/... and app/...
  $repoCandidates = [
    $APP_ROOT . '/app/repos/downloads_repo.php',
    $APP_ROOT . '/app/downloads_repo.php',
  ];
  foreach ($repoCandidates as $rp) {
    if (is_file($rp)) { require_once $rp; break; }
  }

  // Prefer DB (only active; no platform filter => show all)
  if (function_exists('downloads_list') && $pdo instanceof PDO) {
    $downloads = downloads_list($pdo, null, true); // show everything active
  }

  // Fallback to config['downloads'] if DB empty or repo missing
  if (!$downloads && !empty($config['downloads']) && is_array($config['downloads'])) {
    $fallback = $config['downloads'];
    foreach ($fallback as $row) {
      if (empty($row['href'])) { continue; } // skip invalid
      $downloads[] = [
        'name'       => $row['name']       ?? 'Download',
        'href'       => $row['href'],
        'platform'   => is_array($row['platform'] ?? null) ? implode(',', $row['platform']) : ($row['platform'] ?? ''),
        'category'   => $row['category']   ?? '',
        'version'    => $row['version']    ?? '',
        'size_mb'    => $row['size_mb']    ?? '',
        'size_unit'  => $row['size_unit']  ?? '',   // optional in config
        'size_label' => $row['size_label'] ?? '',   // optional in config
        'sha256'     => $row['sha256']     ?? '',
        'notes'      => $row['notes']      ?? '',
        'required'   => !empty($row['required']) ? 1 : 0,
        'sort'       => $row['sort']       ?? 999,
        'is_active'  => 1,
      ];
    }
    usort($downloads, function($a,$b){
      $sa = (int)($a['sort'] ?? 999);
      $sb = (int)($b['sort'] ?? 999);
      if ($sa === $sb) { return strcasecmp($a['name'] ?? '', $b['name'] ?? ''); }
      return $sa <=> $sb;
    });
  }

} catch (Throwable $e) {
  if (defined('DEBUG') && DEBUG) {
    error_log('[downloads] ' . $e->getMessage());
  }
}

// Helper: size formatting (uses size_label > size_unit > auto)
    /** Process and format data. */
function format_download_size(array $d): string {
  if (!empty($d['size_label'])) {
    return (string)$d['size_label']; // e.g. "1.2 GB"
  }
  if (!isset($d['size_mb']) || $d['size_mb'] === '' || $d['size_mb'] === null) {
    return '';
  }
  $mbf  = (float)$d['size_mb'];
  $unit = strtoupper(trim($d['size_unit'] ?? ''));
  $trim = function($n, int $dec) {
    $s = number_format($n, $dec);
    $s = rtrim(rtrim($s, '0'), '.');
    return $s;
  };

  if ($unit === 'GB') return $trim($mbf / 1024, 2) . ' GB';
  if ($unit === 'MB') return $trim($mbf, 1) . ' MB';

  // Auto mode (no unit provided)
  if ($mbf >= 1024) return $trim($mbf / 1024, 2) . ' GB';
  return $trim($mbf, 1) . ' MB';
}
?>

<section id="howto" class="container px-4 pt-16 md:pt-36 pb-24 md:pb-40">
  <!-- Hero -->
  <div class="relative rough-card overflow-hidden p-0 shine mb-6">
    <div class="absolute inset-y-0 left-0 w-1.5 bg-gradient-to-b from-emerald-400/80 to-emerald-500/60"></div>
    <div class="p-6 md:p-8">
      <h1 class="h-display text-3xl md:text-4xl font-extrabold">How to Connect</h1>
      <p class="mt-2 muted">Follow these steps for Windows, macOS, or Linux (Wine).</p>

      <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <a href="<?= e(base_url('register')) ?>" class="btn-ghost">Create Account</a>
        <?php if ($client_link): ?>
          <a href="<?= e($client_link) ?>" class="btn-ghost">Download Client (<?= e($expansion) ?>)</a>
        <?php endif; ?>
        <?php if ($launcher || $patch_link): ?>
          <a href="<?= e($launcher ?: $patch_link) ?>" class="btn-ghost"><?= $launcher ? 'Get Launcher' : 'Get Patch' ?></a>
        <?php endif; ?>
        <?php if ($discord_inv): ?>
          <a href="<?= e($discord_inv) ?>" class="btn-ghost">Join our Discord</a>
        <?php else: ?>
          <a href="<?= e(base_url('status')) ?>" class="btn-ghost">Server Status</a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Stepper -->
  <div class="grid gap-6 lg:grid-cols-3 cards-deferred">
    <div class="space-y-6 lg:col-span-2">
      <!-- Step 1 -->
      <div class="rough-card rough-card-hover p-6">
        <div class="card-radial"></div>
        <div class="flex items-start gap-4">
          <div class="shrink-0 w-9 h-9 bg-brand-400/15 border border-brand-400/20 grid place-items-center font-bold text-brand-300">1</div>
          <div>
            <h2 class="font-semibold text-lg">Create your account</h2>
            <p class="text-neutral-300 mt-1">Use these credentials in-game and on the site.</p>
            <div class="mt-3 flex gap-2">
              <a href="<?= e(base_url('register')) ?>" class="btn-warm btn-sm">Register</a>
              <a href="<?= e(base_url('login')) ?>" class="btn-ghost btn-sm">Login</a>
            </div>
          </div>
        </div>
      </div>

      <!-- Step 2 -->
      <div class="rough-card rough-card-hover p-6">
        <div class="card-radial"></div>
        <div class="flex items-start gap-4">
          <div class="shrink-0 w-9 h-9 bg-brand-400/15 border border-brand-400/20 grid place-items-center font-bold text-brand-300">2</div>
          <div class="w-full">
            <h2 class="font-semibold text-lg">Install the client (<?= e($expansion) ?>)</h2>
            <p class="text-neutral-300 mt-1">Already have a clean client? Skip to Step 3.</p>
            <div class="mt-3 flex flex-wrap gap-2">
              <?php if ($client_link): ?><a href="<?= e($client_link) ?>" class="btn-ghost btn-sm">Download Client</a><?php endif; ?>
              <?php if ($addon_pack): ?><a href="<?= e($addon_pack) ?>" class="btn-ghost btn-sm">Recommended Addons</a><?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- Step 3 -->
      <div class="rough-card rough-card-hover p-6">
        <div class="card-radial"></div>
        <div class="flex items-start gap-4">
          <div class="shrink-0 w-9 h-9 bg-brand-400/15 border border-brand-400/20 grid place-items-center font-bold text-brand-300">3</div>
          <div class="w-full">
            <h2 class="font-semibold text-lg">Point your client to our realm</h2>

            <?php if ($launcher): ?>
              <p class="text-neutral-300 mt-1">Use our launcher to auto-configure.</p>
              <div class="mt-3"><a href="<?= e($launcher) ?>" class="btn-warm btn-sm">Get Launcher</a></div>
              <p class="text-neutral-400 text-sm mt-3">Prefer manual setup? Use the tabs below.</p>
            <?php else: ?>
              <p class="text-neutral-300 mt-1">Set your <code class="px-1.5 py-0.5 bg-black/40 border border-white/10">realmlist.wtf</code> to:</p>
              <div class="mt-2 flex items-center gap-2 flex-wrap">
                <code id="rl" class="inline-block border border-brand-400/30 bg-brand-400/10 px-3 py-2 text-brand-200 font-mono">set realmlist <?= e($realmlist) ?></code>
                <button id="copyRL" class="btn-ghost btn-sm" type="button">Copy</button>
              </div>
            <?php endif; ?>

            <!-- OS Tabs -->
            <div class="mt-5">
              <div class="flex gap-2" data-tabs>
                <button class="tab-btn is-active" data-tab="win">Windows</button>
                <button class="tab-btn" data-tab="mac">macOS</button>
                <button class="tab-btn" data-tab="linux">Linux</button>
              </div>
              <div class="mt-3 rough-card p-4">
                <div class="tab-panel" data-panel="win">
                  <ol class="list-decimal list-inside space-y-1 text-neutral-300">
                    <li>Open your WoW folder.</li>
                    <li>Go to <span class="font-mono">Data\enUS\</span> (or your locale).</li>
                    <li>Open <span class="font-mono">realmlist.wtf</span> in Notepad and replace its contents with:</li>
                  </ol>
                  <pre class="mt-2 bg-black/40 border border-white/10 p-3 overflow-auto"><code>set realmlist <?= e($realmlist) ?></code></pre>
                </div>

                <div class="tab-panel hidden" data-panel="mac">
                  <ol class="list-decimal list-inside space-y-1 text-neutral-300">
                    <li>Open your WoW folder.</li>
                    <li>Navigate to <span class="font-mono">Data/enUS/</span> (or your locale).</li>
                    <li>Edit <span class="font-mono">realmlist.wtf</span> with TextEdit and set:</li>
                  </ol>
                  <pre class="mt-2 bg-black/40 border border-white/10 p-3 overflow-auto"><code>set realmlist <?= e($realmlist) ?></code></pre>
                </div>

                <div class="tab-panel hidden" data-panel="linux">
                  <ol class="list-decimal list-inside space-y-1 text-neutral-300">
                    <li>Find your WoW path under Wine, e.g. <span class="font-mono">~/.wine/drive_c/Program Files/World of Warcraft/</span></li>
                    <li>Go to <span class="font-mono">Data/enUS/</span> (or your locale).</li>
                    <li>Edit <span class="font-mono">realmlist.wtf</span> and set:</li>
                  </ol>
                  <pre class="mt-2 bg-black/40 border border-white/10 p-3 overflow-auto"><code>set realmlist <?= e($realmlist) ?></code></pre>
                </div>
              </div>
            </div>

            <?php if ($patch_link): ?>
              <div class="mt-4"><a href="<?= e($patch_link) ?>" class="btn-ghost btn-sm">Download Patch</a></div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Step 4 -->
      <div class="rough-card rough-card-hover p-6">
        <div class="card-radial"></div>
        <div class="flex items-start gap-4">
          <div class="shrink-0 w-9 h-9 bg-brand-400/15 border border-brand-400/20 grid place-items-center font-bold text-brand-300">4</div>
          <div>
            <h2 class="font-semibold text-lg">Launch &amp; Login</h2>
            <p class="text-neutral-300 mt-1">Start with <span class="font-mono">Wow.exe</span> (or your launcher), then log in using your website account.</p>
            <div class="mt-3 flex items-center gap-3">
              <a href="<?= e(base_url('status')) ?>" class="btn-ghost btn-sm">Check Realm Status</a>
              <?php if ($discord_inv): ?><a href="<?= e($discord_inv) ?>" class="btn-ghost btn-sm">Get Help on Discord</a><?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- Troubleshooting -->
      <div class="rough-card p-6" data-accordion>
        <h2 class="font-semibold text-lg mb-4">Troubleshooting</h2>

        <details class="group border border-white/10 bg-white/[0.02] open:!bg-white/[0.04] p-3 mb-2" open>
          <summary class="cursor-pointer font-medium flex items-center justify-between">
            Can't connect / still on retail servers
            <span class="opacity-60 group-open:rotate-180 transition inline-block">▾</span>
          </summary>
          <div class="mt-2 text-neutral-300">
            Ensure your <span class="font-mono">realmlist.wtf</span> contains:
            <pre class="mt-2 bg-black/40 border border-white/10 p-3 overflow-auto"><code>set realmlist <?= e($realmlist) ?></code></pre>
            Delete the <span class="font-mono">Cache</span> folder in your WoW directory and restart the game.
          </div>
        </details>

        <details class="group border border-white/10 bg-white/[0.02] p-3 mb-2">
          <summary class="cursor-pointer font-medium flex items-center justify-between">
            Wrong client version / "unable to validate game version"
            <span class="opacity-60 group-open:rotate-180 transition inline-block">▾</span>
          </summary>
          <div class="mt-2 text-neutral-300">
            Use a clean <?= e($expansion) ?> client.
            <?php if ($client_link): ?><div class="mt-2"><a href="<?= e($client_link) ?>" class="btn-ghost btn-sm">Get a Clean Client</a></div><?php endif; ?>
          </div>
        </details>

        <details class="group border border-white/10 bg-white/[0.02] p-3">
          <summary class="cursor-pointer font-medium flex items-center justify-between">
            Account issues (can't log in)
            <span class="opacity-60 group-open:rotate-180 transition inline-block">▾</span>
          </summary>
          <div class="mt-2 text-neutral-300">
            Double-check username/password. If you just created the account, wait a minute and try again.
            <?php if ($discord_inv): ?><div class="mt-2"><a href="<?= e($discord_inv) ?>" class="btn-ghost btn-sm">Contact Support on Discord</a></div><?php endif; ?>
          </div>
        </details>
      </div>
    </div>

    <!-- Right column -->
    <div class="space-y-6">
      <?php if (!empty($downloads)): ?>
        <?php
          // Group by category (fallback "Other"), preserving incoming order
          $byCat = [];
          foreach ($downloads as $d) {
            $c = trim((string)($d['category'] ?? ''));
            $key = $c !== '' ? $c : 'Other';
            $byCat[$key][] = $d;
          }
        ?>
        <div class="rough-card p-6">
          <div class="flex items-center justify-between mb-4">
            <h2 class="font-semibold text-lg">Downloads</h2>
          </div>

          <?php foreach ($byCat as $catName => $list): ?>
            <div class="mb-5 last:mb-0">
              <div class="flex items-center gap-2 mb-2">
                <span class="kicker"><?= e($catName) ?></span>
                <span class="flex-1 h-px bg-white/10"></span>
              </div>

              <ul class="divide-y divide-white/10">
                <?php foreach ($list as $d):
                  $name  = $d['name'] ?? 'Download';
                  $href  = $d['href'] ?? '#';
                  $ver   = trim((string)($d['version'] ?? ''));
                  $notes = trim((string)($d['notes'] ?? ''));
                  $req   = !empty($d['required']);
                  $size  = format_download_size($d);
                ?>
                  <li class="py-3 min-h-[60px] flex items-center justify-between gap-4">
                    <div class="min-w-0">
                      <div class="font-medium truncate"><?= e($name) ?></div>

                      <div class="mt-0.5 text-xs text-neutral-400 flex items-center flex-wrap gap-1.5">
                        <?php if ($req): ?>
                          <span class="inline-flex items-center rounded px-1.5 py-0.5 border border-brand-400/40 bg-brand-400/10 text-brand-200 font-semibold">
                            ★ Required
                          </span>
                        <?php else: ?>
                          <span class="opacity-70">Optional</span>
                        <?php endif; ?>

                        <?php if ($ver || $size): ?>
                          <span class="opacity-50">·</span>
                          <span><?= $ver ? 'v'.e($ver) : '' ?><?= ($ver && $size) ? ' · ' : '' ?><?= $size ? e($size) : '' ?></span>
                        <?php endif; ?>
                      </div>

                      <?php if ($notes): ?>
                        <div class="text-xs text-neutral-400 mt-1"><?= e($notes) ?></div>
                      <?php endif; ?>
                    </div>

                    <a class="btn btn-warm btn-sm shrink-0 self-center"
                       href="<?= e($href) ?>" target="_blank" rel="noopener">
                      Download
                    </a>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <!-- Community card (unchanged) -->
      <div class="rough-card p-6">
        <div class="flex items-center justify-between mb-4">
          <h2 class="font-semibold text-lg">Community</h2>

          <?php if ($discord_inv): ?>
            <a href="<?= e($discord_inv) ?>" class="btn-warm btn-sm inline-flex"
               target="_blank" rel="noopener">
              Join Discord
            </a>
          <?php endif; ?>
        </div>

        <?php if ($discord_show_widget && $discord_gid): ?>
          <div class="discord-widget overflow-hidden bg-black/20">
            <iframe
              src="https://discord.com/widget?id=<?= e($discord_gid) ?>&theme=<?= e($discord_theme) ?>"
              width="100%" height="380"
              class="block w-full"
              allowtransparency="true" frameborder="0"
              style="border:0;outline:0;box-shadow:none"
              sandbox="allow-popups allow-popups-to-escape-sandbox allow-same-origin allow-scripts"
              title="Discord Widget">
            </iframe>
          </div>
        <?php else: ?>
          <p class="text-neutral-300">Join our Discord to get help and meet the community.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Page-scoped helpers -->
  <script type="module" src="<?= e(theme_asset_url('js/howto-tabs.js')) ?>"></script>
  <script type="module" src="<?= e(theme_asset_url('js/howto-accordion.js')) ?>"></script>
  <script>
    // Copy realmlist
    document.getElementById('copyRL')?.addEventListener('click', () => {
      const text = document.getElementById('rl')?.innerText?.trim();
      if (!text) return;
      navigator.clipboard.writeText(text).then(() => {
        const btn = document.getElementById('copyRL');
        const prev = btn.textContent;
        btn.textContent = 'Copied!';
        setTimeout(() => (btn.textContent = prev), 1200);
      });
    });
  </script>
</section>
<script defer src="<?= e(theme_asset_url('js/fog-loader.js')) ?>"></script>
