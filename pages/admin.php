<?php
/**
 * Admin panel - Module and theme management interface for administrators
 * Provides controls for enabling/disabling modules and switching themes
 * Requires administrator permissions (configurable minimum level)
 */
declare(strict_types=1);

$APP_ROOT = __DIR__ . '/../app';
require_once $APP_ROOT . '/auth.php';

// Bridge functions to integrate with clean system
if (!function_exists('modules_bootstrap')) {
    function modules_bootstrap(): void {
        if (function_exists('get_clean_module_repo')) {
            get_clean_module_repo();
        }
    }
}

if (!function_exists('modules_pdo')) {
    function modules_pdo(): PDO {
        if (function_exists('get_clean_app')) {
            return get_clean_app()->getDb()->getPdo();
        }
        return $GLOBALS['pdo'];
    }
}

if (!function_exists('module_enabled')) {
    function module_enabled(string $name): bool {
        if (function_exists('clean_module_enabled')) {
            return clean_module_enabled($name);
        }
        
        // Fallback to direct database query
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            try {
                $name = preg_replace('/[^a-z0-9\-_]/i', '', $name);
                if (empty($name)) return false;
                
                $stmt = $GLOBALS['pdo']->prepare("SELECT enabled FROM modules WHERE name = ? LIMIT 1");
                $stmt->execute([$name]);
                $enabled = $stmt->fetchColumn();
                return (bool)$enabled;
            } catch (Exception $e) {
                return false;
            }
        }
        
        return false;
    }
}

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$loginUrl = function_exists('base_url') ? base_url('login') : '/login';
$user = auth_user();

// Require authentication
if (!$user) { 
    header('Location: ' . $loginUrl); 
    exit; 
}

global $authPdo, $config;

if (!$authPdo) {
    http_response_code(500);
    die('Database connection error');
}

// Check administrator permissions
$minPermissionId = (int)($config['admin_min_permission_id'] ?? 191);
$userId = (int)$user['id'];

$isAdmin = false;
if (function_exists('auth_is_admin')) {
    $isAdmin = auth_is_admin($authPdo, $userId, $minPermissionId);
} else {
    // Fallback permission check
    try {
        $stmt = $authPdo->prepare("SELECT COUNT(*) FROM rbac_account_permissions WHERE accountId = ? AND permissionId >= ? AND granted = 1");
        $stmt->execute([$userId, $minPermissionId]);
        $isAdmin = (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        $isAdmin = false;
    }
}

if (!$isAdmin) {
    http_response_code(403);
    die('Access denied. Administrator permissions required. You need permission level ' . $minPermissionId . ' or higher.');
}

$THEME = theme_current();

// Define known modules with descriptions
$KNOWN_MODULES = [
  ['name' => 'news',       'label' => 'News',          'desc' => 'Stacked posts on the home page.'],
  ['name' => 'bloodmarks', 'label' => 'Bloodmarks',    'desc' => 'Bloodmarking leaderboard card.'],
  ['name' => 'honorable_kills', 'label' => 'Honorable Kills', 'desc' => 'PvP honorable kills leaderboard.'],
  ['name' => 'realms',     'label' => 'Realm Status',  'desc' => 'Live status and population widgets.'],
  ['name' => 'discord',    'label' => 'Discord',       'desc' => 'Discord CTA and/or embedded widget.'],
  ['name' => 'armory',     'label' => 'Armory',        'desc' => 'Character lookup page + tooltip.'],
  ['name' => 'chat',        'label' => 'Live Chat',     'desc' => 'Real-time chat system for players.'],
  ['name' => 'header_chat', 'label' => 'Header Chat', 'desc' => 'Compact chat widget in header.'],
  ['name' => 'announcements', 'label' => 'Announcements', 'desc' => 'Announcements on top of home page.'],
];

// Merge custom modules from database
$allNames = array_column($KNOWN_MODULES, 'name');
try {
  modules_bootstrap();
  $pdo = modules_pdo();
  $stmt = $pdo->query("SELECT DISTINCT name FROM modules");
  foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $n) {
    if (!in_array($n, $allNames, true)) {
      $KNOWN_MODULES[] = ['name'=>$n, 'label'=>ucfirst($n), 'desc'=>'Custom module'];
      $allNames[] = $n;
    }
  }
} catch (Throwable $e) {}

if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(16));
$csrfToken = $_SESSION['_csrf'];

// Get current module states
$states = [];
foreach ($allNames as $n) $states[$n] = module_enabled($n);

$base = theme_web_base(); // "" or "/public"

// Determine API endpoints (prefer clean APIs if available)
$apiToggle = $base . '/api/module-toggle.php';
$apiTheme  = $base . '/api/theme-set.php';

if (file_exists(__DIR__ . '/../public/api/modules-clean.php')) {
    $apiToggle = $base . '/api/modules-clean.php';
}
if (file_exists(__DIR__ . '/../public/api/themes-clean.php')) {
    $apiTheme = $base . '/api/themes-clean.php';
}

// Scan available themes
$themes = [];
foreach (new DirectoryIterator(THEME_DIR) as $f) {
  if ($f->isDot() || !$f->isDir()) continue;
  $slug = $f->getFilename();
  if (preg_match('~^[a-z0-9\-_]+$~i', $slug)) $themes[] = $slug;
}
sort($themes);

$lock     = !empty($GLOBALS['config']['theme_lock']);
$cfgTheme = $GLOBALS['config']['theme'] ?? null;
?>
<section class="container px-4 pt-10">
  <h1 class="h-display text-3xl font-extrabold text-center">Admin</h1>
  <p class="mt-1 muted text-center">
    Welcome, <?= e($user['username']) ?>.
    <span class="text-xs muted">Active theme: <strong><?= e($THEME) ?></strong></span>
  </p>

  <div class="mt-6 rough-card p-0 overflow-hidden max-w-2xl mx-auto admin-slab">
    <div class="px-5 py-4 slab-head">
      <h2 class="font-semibold text-lg">Modules</h2>
      <div class="right">
        <label for="theme-picker" class="text-sm muted">Theme</label>
        <select id="theme-picker" class="tui-select" <?= $lock ? 'disabled' : '' ?>>
          <?php foreach ($themes as $t): ?>
            <option value="<?= e($t) ?>" <?= $t === $THEME ? 'selected' : '' ?>>
              <?= e($t) ?><?= ($lock && $cfgTheme === $t ? ' (locked)' : '') ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <ul class="module-list">
      <?php foreach ($KNOWN_MODULES as $mod):
        $name  = $mod['name'];
        $label = $mod['label'] ?? ucfirst($name);
        $desc  = $mod['desc']  ?? '';
        $on    = !empty($states[$name]);
      ?>
      <li class="module-row" data-row>
        <div class="min-w-0">
          <div class="title"><?= e($label) ?></div>
          <?php if ($desc): ?><div class="desc"><?= e($desc) ?></div><?php endif; ?>
        </div>

        <label class="tui-toggle" data-name="<?= e($name) ?>" title="<?= $on ? 'Enabled' : 'Disabled' ?>">
          <input type="checkbox" <?= $on ? 'checked' : '' ?> aria-label="<?= e($label) ?>">
          <span class="track"></span>
          <span class="knob"></span>
        </label>
      </li>
      <?php endforeach; ?>
    </ul>

    <div class="px-5 py-4 flex items-center justify-between">
      <a href="/" class="btn btn-ghost">Back to site</a>
      <a href="<?= e(function_exists('base_url') ? base_url('logout') : '/logout') ?>" class="btn btn-ghost">Logout</a>
    </div>
  </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const csrf      = <?= json_encode($csrfToken) ?>;
  const apiToggle = <?= json_encode($apiToggle) ?>;
  const apiTheme  = <?= json_encode($apiTheme) ?>;
  const locked    = <?= json_encode($lock) ?>;

  async function asJson(res){
    const txt = await res.text();
    try { return JSON.parse(txt); } catch { throw new Error(txt || 'No JSON'); }
  }

  document.querySelectorAll('.module-row[data-row]').forEach(row => {
    const input = row.querySelector('.tui-toggle input');
    row.addEventListener('click', (e) => {
      if (e.target.closest('.tui-toggle')) return;
      input.checked = !input.checked;
      input.dispatchEvent(new Event('change', { bubbles: true }));
    });
  });

  // Global toggle
  document.querySelectorAll('.tui-toggle').forEach(t => {
    const input = t.querySelector('input');
    const name  = t.getAttribute('data-name');
    const setTitle = () => t.title = input.checked ? 'Enabled' : 'Disabled';
    setTitle();

    input.addEventListener('change', async () => {
      try {
        const res = await fetch(apiToggle, {
          method: 'POST',
          credentials: 'same-origin',
          cache: 'no-store',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrf,
            'Accept': 'application/json'
          },
          body: JSON.stringify({ name, enabled: input.checked ? 1 : 0, _csrf: csrf })
        });
        const json = await asJson(res);
        if (!res.ok || !json.ok) throw new Error(json.error || 'Save failed');
        setTitle();
      } catch (err) {
        input.checked = !input.checked;
        setTitle();
        alert('Could not save: ' + err.message);
      }
    });
  });

  // Theme picker still works for site look & feel
  const picker = document.getElementById('theme-picker');
  if (picker && !locked) {
    picker.addEventListener('change', async () => {
      try {
        const res = await fetch(apiTheme, {
          method: 'POST',
          credentials: 'same-origin',
          cache: 'no-store',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrf,
            'Accept': 'application/json'
          },
          body: JSON.stringify({ theme: picker.value, _csrf: csrf })
        });
        const json = await asJson(res);
        if (!res.ok || !json.ok) throw new Error(json.error || 'Save failed');
        document.cookie = 'theme_preview=; Max-Age=0; path=/';
        const url = new URL(location.href);
        url.searchParams.set('clear_theme', '1');
        url.searchParams.set('t', Date.now());
        location.href = url.toString();
      } catch (err) {
        alert('Could not switch theme: ' + err.message);
      }
    });
  }
});
</script>
