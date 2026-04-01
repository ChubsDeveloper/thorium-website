<?php
/**
 * pages/how-to.php
 * Recommended path: Use the Launcher (auto-downloads patches & config)
 */

// Pull from config
$config = $GLOBALS['config'] ?? [];

// Connect/config
$connect       = $config['connect'] ?? [];
$client_link   = $connect['client_link']    ?? '';
$launcher      = $connect['launcher_link']  ?? '';
$addon_pack    = $connect['addon_pack']     ?? '';
$expansion     = $connect['expansion']      ?? '3.3.5a';

// Optional: individual patch links (use these keys in config.php → ['connect'])
$patch5_link   = $connect['patch5_link']    ?? ($connect['patch_5_link'] ?? '');
$patch6_link   = $connect['patch6_link']    ?? ($connect['patch_6_link'] ?? '');
$patch7_link   = $connect['patch7_link']    ?? ($connect['patch_7_link'] ?? '');
$patch8_link   = $connect['patch8_link']    ?? ($connect['patch_link']  ?? ''); // legacy alias
$patchE_link   = $connect['patchE_link']    ?? ($connect['patch_e_link'] ?? '');

// Which patches are required? (override with ['connect']['required_patches'] = ['5','6','7','8'] or similar)
$required_patches = $connect['required_patches'] ?? ['5','6','7','8'];
$req = function(string $code) use ($required_patches): bool {
  return in_array($code, array_map('strval', $required_patches), true);
};

// Build patch meta
$patches = [
  [
    'code' => '5',
    'name' => 'Patch-5',
    'desc' => 'Custom models, creatures, mounts & NPCs.',
    'required' => $req('5'),
    'href' => $patch5_link,
    'notes' => [],
  ],
  [
    'code' => '6',
    'name' => 'Patch-6',
    'desc' => 'Custom maps, gameobjects, doodads, and more.',
    'required' => $req('6'),
    'href' => $patch6_link,
    'notes' => [],
  ],
  [
    'code' => '7',
    'name' => 'Patch-7',
    'desc' => 'Weapons, items, armours, and more.',
    'required' => $req('7'),
    'href' => $patch7_link,
    'notes' => [],
  ],
  [
    'code' => '8',
    'name' => 'Patch-8',
    'desc' => 'Spells, icons, custom currencies, melee fixes, etc.',
    'required' => $req('8'),
    'href' => $patch8_link,
    'notes' => [
      'Updated frequently with fixes/changes.',
      'Launcher also auto-configures your realmlist.',
    ],
  ],
  [
    'code' => 'E',
    'name' => 'Patch-E',
    'desc' => 'Visual upgrades: textures, icons and overall improvements.',
    'required' => $req('E'), // default false unless you set it
    'href' => $patchE_link,
    'notes' => ['Optional; not required to play.'],
  ],
];

// Discord
$dc                  = $config['discord'] ?? [];
$discord_inv         = $dc['invite_url'] ?? '';
$discord_gid         = $dc['guild_id'] ?? '';
$discord_theme       = in_array(($dc['widget_theme'] ?? 'dark'), ['dark','light'], true) ? $dc['widget_theme'] : 'dark';
$discord_show_widget = !empty($dc['show_widget']);

$prefer_launcher = !empty($launcher);

// -----------------------------
// Downloads (DB) — unchanged logic
// -----------------------------
$downloads = [];
$pdo = null;

try {
  if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
    $pdo = $GLOBALS['pdo'];
  } elseif (function_exists('app') && method_exists(app(), 'getDb')) {
    $pdo = app()->getDb()->getPdo();
  } elseif (function_exists('get_database_connection')) {
    $pdo = get_database_connection();
  }

  if ($pdo instanceof PDO) {
    $APP_ROOT = dirname(__DIR__);
    if (file_exists($APP_ROOT . '/app/downloads_repo.php')) {
      require_once $APP_ROOT . '/app/downloads_repo.php';
    }
    if (function_exists('downloads_list')) {
      $downloads = downloads_list($pdo, null, true);

      if (empty($downloads)) {
        if (function_exists('downloads_create_table')) downloads_create_table($pdo);
        if (function_exists('downloads_insert_samples')) {
          downloads_insert_samples($pdo);
          $downloads = downloads_list($pdo, null, true);
        }
      }
    }
  }

  // Fallback to config if DB failed
  if (empty($downloads) && !empty($config['downloads']) && is_array($config['downloads'])) {
    $fallback = $config['downloads'];
    foreach ($fallback as $row) {
      if (empty($row['href'])) { continue; }
      $downloads[] = [
        'name'       => $row['name'] ?? 'Download',
        'href'       => $row['href'],
        'platform'   => is_array($row['platform'] ?? null) ? implode(',', $row['platform']) : ($row['platform'] ?? ''),
        'category'   => $row['category'] ?? '',
        'version'    => $row['version'] ?? '',
        'size_mb'    => $row['size_mb'] ?? '',
        'size_unit'  => $row['size_unit'] ?? '',
        'size_label' => $row['size_label'] ?? '',
        'sha256'     => $row['sha256'] ?? '',
        'notes'      => $row['notes'] ?? '',
        'required'   => !empty($row['required']) ? 1 : 0,
        'sort'       => $row['sort'] ?? 999,
        'is_active'  => 1,
      ];
    }
    usort($downloads, function($a,$b){
      $sa = (int)($a['sort'] ?? 999); $sb = (int)($b['sort'] ?? 999);
      if ($sa === $sb) { return strcasecmp($a['name'] ?? '', $b['name'] ?? ''); }
      return $sa <=> $sb;
    });
  }

  // Emergency fallback
  if (empty($downloads)) {
    $downloads = [
      [
        'name' => 'WoW Client (3.3.5a)',
        'href' => $client_link ?: '#',
        'category' => 'Client',
        'version' => '3.3.5a',
        'size_label' => '4.0 GB',
        'required' => 1,
        'sort' => 1,
        'notes' => 'Clean World of Warcraft client',
        'is_active' => 1
      ],
      [
        'name' => 'Recommended Addons',
        'href' => $addon_pack ?: '#',
        'category' => 'Addons',
        'size_label' => '25 MB',
        'required' => 0,
        'sort' => 2,
        'notes' => 'Optional QoL addons',
        'is_active' => 1
      ]
    ];
  }

} catch (Throwable $e) {
  if (defined('DEBUG') && DEBUG) { error_log('[downloads] ' . $e->getMessage()); }
  if (empty($downloads)) {
    $downloads = [[
      'name' => 'WoW Client (3.3.5a)',
      'href' => '#', 'category' => 'Client', 'version' => '3.3.5a',
      'size_label' => '4.0 GB', 'required' => 1, 'sort' => 1, 'is_active' => 1
    ]];
  }
}

// Helper: size formatting
function format_download_size(array $d): string {
  if (!empty($d['size_label'])) return (string)$d['size_label'];
  if (!isset($d['size_mb']) || $d['size_mb'] === '' || $d['size_mb'] === null) return '';
  $mbf  = (float)$d['size_mb'];
  $unit = strtoupper(trim($d['size_unit'] ?? ''));
  $fmt = function($n,$dec){ $s=number_format($n,$dec); return rtrim(rtrim($s,'0'),'.'); };
  if ($unit === 'GB') return $fmt($mbf/1024,2) . ' GB';
  if ($unit === 'MB') return $fmt($mbf,1) . ' MB';
  return $mbf >= 1024 ? $fmt($mbf/1024,2) . ' GB' : $fmt($mbf,1) . ' MB';
}
?>

<section id="howto" class="container px-4 pt-16 md:pt-36 pb-24 md:pb-40">
  <!-- Hero -->
  <div class="relative rough-card overflow-hidden p-0 shine mb-6">
    <div class="absolute inset-y-0 left-0 w-1.5 bg-gradient-to-b from-emerald-400/80 to-emerald-500/60"></div>
    <div class="p-6 md:p-8">
      <h1 class="h-display text-3xl md:text-4xl font-extrabold">How to Connect</h1>
      <p class="mt-2 muted">
        The easiest way: <strong>Download the Thorium Launcher</strong>. It will
        <strong>auto-download required patches</strong>, <strong>set your realmlist</strong>, and keep things up to date.
        Manual patch links are available below if you really need them.
      </p>

      <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <a href="<?= e(base_url('register')) ?>" class="btn-ghost">Create Account</a>
        <?php if ($launcher): ?>
          <a href="<?= e($launcher) ?>" class="btn-warm">Get Launcher (Recommended)</a>
        <?php endif; ?>
        <?php if ($client_link): ?>
          <a href="<?= e($client_link) ?>" class="btn-ghost">Download Client (<?= e($expansion) ?>)</a>
        <?php endif; ?>
        <?php if ($discord_inv): ?>
          <a href="<?= e($discord_inv) ?>" class="btn-ghost">Join our Discord</a>
        <?php else: ?>
          <a href="<?= e(base_url('status')) ?>" class="btn-ghost">Server Status</a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Steps -->
  <div class="grid gap-6 lg:grid-cols-3 cards-deferred">
    <div class="space-y-6 lg:col-span-2">
      <!-- Step 1: Launcher -->
      <div class="rough-card rough-card-hover p-6">
        <div class="card-radial"></div>
        <div class="flex items-start gap-4">
          <div class="shrink-0 w-9 h-9 bg-brand-400/15 border border-brand-400/20 grid place-items-center font-bold text-brand-300">1</div>
          <div class="w-full">
            <h2 class="font-semibold text-lg">Get the Thorium Launcher</h2>
            <p class="text-neutral-300 mt-1">
              The launcher auto-downloads required patches and configures your realmlist.
            </p>
            <div class="mt-3 flex flex-wrap gap-2">
              <?php if ($launcher): ?>
                <a href="<?= e($launcher) ?>" class="btn-warm btn-sm">Download Launcher</a>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- Step 2: Place it in your WoW folder -->
      <div class="rough-card rough-card-hover p-6">
        <div class="card-radial"></div>
        <div class="flex items-start gap-4">
          <div class="shrink-0 w-9 h-9 bg-brand-400/15 border border-brand-400/20 grid place-items-center font-bold text-brand-300">2</div>
          <div class="w-full">
            <h2 class="font-semibold text-lg">Put the Launcher in your WoW folder</h2>
            <ol class="list-decimal list-inside space-y-1 text-neutral-300 mt-1">
              <li>Extract/copy the <strong>Thorium Launcher</strong> into your WoW folder.</li>
              <li>Run the launcher. It will fetch our custom patches for you.</li>
            </ol>
          </div>
        </div>
      </div>

      <!-- Step 3: Play -->
      <div class="rough-card rough-card-hover p-6">
        <div class="card-radial"></div>
        <div class="flex items-start gap-4">
          <div class="shrink-0 w-9 h-9 bg-brand-400/15 border border-brand-400/20 grid place-items-center font-bold text-brand-300">3</div>
          <div>
            <h2 class="font-semibold text-lg">Launch &amp; Login</h2>
            <p class="text-neutral-300 mt-1">
              Press <strong>Play</strong> in the launcher and log in with your website account.
            </p>
            <div class="mt-3 flex items-center gap-3 flex-wrap">
              <a href="<?= e(base_url('register')) ?>" class="btn-ghost btn-sm">Create Account</a>
              <?php if ($discord_inv): ?><a href="<?= e($discord_inv) ?>" class="btn-ghost btn-sm">Get Help on Discord</a><?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- Manual install (without the launcher) -->
<div class="rough-card p-6 relative" data-accordion>
  <!-- Let clicks pass through -->
  <div class="card-radial pointer-events-none"></div>

  <details class="group border border-white/10 bg-white/[0.02] open:!bg-white/[0.04] p-3">
    <summary class="cursor-pointer font-semibold text-lg flex items-center justify-between">
      Manual install (without the launcher)
      <span class="opacity-60 group-open:rotate-180 transition inline-block">▾</span>
    </summary>

    <div class="mt-3 space-y-4 text-neutral-300">
      <p><strong>Not recommended.</strong> Only use this if you can’t run the launcher.</p>

      <div>
        <h3 class="font-medium">1) Download a clean client (<?= e($expansion) ?>)</h3>
        <?php if ($client_link): ?>
          <a href="<?= e($client_link) ?>" class="btn-ghost btn-sm mt-2 inline-block">Get Client</a>
        <?php else: ?>
          <p class="text-sm opacity-80 mt-1">Client link not available here.</p>
        <?php endif; ?>
      </div>

      <div id="patches">
        <h3 class="font-medium mt-4">2) Download required patches &amp; put them in <span class="font-mono">Data</span></h3>
        <p class="text-sm opacity-80">These are the same files the launcher would fetch automatically.</p>

        <ul class="divide-y divide-white/10 mt-3">
          <?php foreach ($patches as $p): ?>
            <li class="py-3 flex items-start justify-between gap-4">
              <div class="min-w-0">
                <div class="flex items-center gap-2">
                  <div class="font-medium"><?= e($p['name']) ?></div>
                  <span class="text-xs px-1.5 py-0.5 rounded border <?= $p['required'] ? 'border-brand-400/40 bg-brand-400/10 text-brand-200' : 'border-white/15 bg-white/5 text-neutral-300' ?>">
                    <?= $p['required'] ? 'Required' : 'Optional' ?>
                  </span>
                </div>
                <div class="text-neutral-300 mt-1"><?= e($p['desc']) ?></div>
                <?php if (!empty($p['notes'])): ?>
                  <ul class="mt-2 text-sm text-neutral-400 space-y-1">
                    <?php foreach ($p['notes'] as $n): ?>
                      <li>Note: <?= e($n) ?></li>
                    <?php endforeach; ?>
                  </ul>
                <?php endif; ?>
                <div class="text-xs text-neutral-400 mt-1">
                  Place <span class="font-mono">Patch-*.MPQ</span> files into your WoW <span class="font-mono">Data</span> folder (e.g. <span class="font-mono">World of Warcraft\Data</span>).
                </div>
              </div>

              <?php if (!empty($p['href'])): ?>
                <a class="btn btn-ghost btn-sm shrink-0 self-center" href="<?= e($p['href']) ?>" target="_blank" rel="noopener">
                  Download
                </a>
              <?php else: ?>
                <span class="text-xs opacity-60 self-center">Link unavailable</span>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>

      <div class="mt-4">
        <h3 class="font-medium">3) Clear Cache (recommended)</h3>
        <p class="text-sm opacity-80">
          Delete the <span class="font-mono">Cache</span> folder in your WoW directory before launching.
        </p>
      </div>

      <div class="mt-4">
        <h3 class="font-medium">4) Launch the game</h3>
        <p class="text-sm opacity-80">
          Start the game from the Thorium executable <span class="font-mono">TWoW.exe</span>.
          <strong>No realmlist edits are needed</strong> — the patch and our executable handle it.
          If you connect to the wrong place, double-check patches are in <span class="font-mono">Data</span> and clear <span class="font-mono">Cache</span>.
        </p>
      </div>
    </div>
  </details>
</div>

      <!-- Troubleshooting -->
      <div class="rough-card p-6" data-accordion>
        <h2 class="font-semibold text-lg mb-4">Troubleshooting</h2>

        <details class="group border border-white/10 bg-white/[0.02] open:!bg-white/[0.04] p-3 mb-2" open>
          <summary class="cursor-pointer font-medium flex items-center justify-between">
            Launcher says “patches missing”
            <span class="opacity-60 group-open:rotate-180 transition inline-block">▾</span>
          </summary>
          <div class="mt-2 text-neutral-300">
            <ul class="list-disc list-inside space-y-1">
              <li>Put the <strong>launcher</strong> in the same folder as your <span class="font-mono">Data</span> directory.</li>
              <li>Ensure your firewall/AV isn’t blocking the download.</li>
              <li>Delete the <span class="font-mono">Cache</span> folder and retry.</li>
            </ul>
          </div>
        </details>

        <details class="group border border-white/10 bg-white/[0.02] p-3 mb-2">
          <summary class="cursor-pointer font-medium flex items-center justify-between">
            Connecting to the wrong realm / retail?
            <span class="opacity-60 group-open:rotate-180 transition inline-block">▾</span>
          </summary>
          <div class="mt-2 text-neutral-300">
            <ul class="list-disc list-inside space-y-1">
              <li>Launch the game via the <strong>Thorium Launcher</strong> so it sets the realmlist.</li>
              <li>Ensure required patches (especially <span class="font-mono">patch-8.MPQ</span>) are in <span class="font-mono">Data</span>.</li>
              <li>Delete <span class="font-mono">Cache</span> and try again.</li>
            </ul>
          </div>
        </details>

        <details class="group border border-white/10 bg-white/[0.02] p-3">
          <summary class="cursor-pointer font-medium flex items-center justify-between">
            Account issues (can’t log in)
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
          // Group by category (fallback "Other"), preserving order
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
                  $reqd  = !empty($d['required']);
                  $size  = format_download_size($d);
                ?>
                  <li class="py-3 min-h-[60px] flex items-center justify-between gap-4">
                    <div class="min-w-0">
                      <div class="font-medium truncate"><?= e($name) ?></div>
                      <div class="mt-0.5 text-xs text-neutral-400 flex items-center flex-wrap gap-1.5">
                        <?php if ($reqd): ?>
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

                    <a class="btn btn-warm btn-sm shrink-0 self-center" href="<?= e($href) ?>" target="_blank" rel="noopener">
                      Download
                    </a>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="rough-card p-6">
          <h2 class="font-semibold text-lg mb-4">Downloads</h2>
          <p class="text-neutral-400">No downloads available at the moment.</p>
        </div>
      <?php endif; ?>

      <!-- Community -->
      <div class="rough-card p-6">
        <div class="flex items-center justify-between mb-4">
          <h2 class="font-semibold text-lg">Community</h2>
          <?php if ($discord_inv): ?>
            <a href="<?= e($discord_inv) ?>" class="btn-warm btn-sm inline-flex" target="_blank" rel="noopener">
              Join Discord
            </a>
          <?php endif; ?>
        </div>

        <?php if ($discord_show_widget && $discord_gid): ?>
          <div class="discord-widget overflow-hidden bg-black/20">
            <iframe
              src="https://discord.com/widget?id=<?= e($discord_gid) ?>&theme=<?= e($discord_theme) ?>"
              width="100%" height="380" class="block w-full"
              allowtransparency="true" frameborder="0" style="border:0;outline:0;box-shadow:none"
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
  <script type="module" src="<?= e(theme_asset_url('js/howto-accordion.js')) ?>"></script>
</section>
<script defer src="<?= e(theme_asset_url('js/fog-loader.js')) ?>"></script>
