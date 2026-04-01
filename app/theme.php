<?php
/**
 * Theme system - Handles theme loading, asset management, and template resolution
 * Provides functions for theme switching, asset URLs, and template path resolution
 */
declare(strict_types=1);

const THEME_DIR = __DIR__ . '/../themes';

// Settings helper function with fallback to database
if (!function_exists('settings_get')) {
    function settings_get(string $key, ?string $default = null): ?string {
        if (function_exists('clean_settings_get')) {
            return clean_settings_get($key, $default);
        }
        
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            try {
                $stmt = $GLOBALS['pdo']->prepare("SELECT v FROM settings WHERE k = ? LIMIT 1");
                $stmt->execute([$key]);
                $value = $stmt->fetchColumn();
                return $value !== false ? (string)$value : $default;
            } catch (Exception $e) {
                return $default;
            }
        }
        
        return $default;
    }
}

if (!defined('ROOT_DIR')) {
    define('ROOT_DIR', dirname(__DIR__));
}

// Theme validation and management
function theme_exists(string $slug): bool {
    $safe = preg_replace('~[^a-z0-9\-_]~i', '', $slug);
    return $safe !== '' && is_dir(THEME_DIR . '/' . $safe);
}

// Theme selection with priority: config lock > URL param > database > cookie > default
function theme_current(): string {
    static $cached;
    if ($cached) return $cached;

    if (!isset($GLOBALS['config']) || !is_array($GLOBALS['config'])) {
        $cfg = __DIR__ . '/config.php';
        $GLOBALS['config'] = is_file($cfg) ? require $cfg : [];
    }

    $cfgTheme = $GLOBALS['config']['theme'] ?? null;
    $lock     = !empty($GLOBALS['config']['theme_lock']);

    if (!empty($_GET['clear_theme']) && !headers_sent()) {
        setcookie('theme_preview', '', time() - 3600, '/');
        unset($_COOKIE['theme_preview']);
    }

    if ($lock && $cfgTheme) {
        return $cached = preg_replace('~[^a-z0-9\-_]~i', '', $cfgTheme);
    }

    if (!empty($_GET['theme'])) {
        $t = preg_replace('~[^a-z0-9\-_]~i', '', (string)$_GET['theme']);
        if ($t !== '' && theme_exists($t)) {
            if (!headers_sent()) setcookie('theme_preview', $t, time() + 3600, '/');
            return $cached = $t;
        }
    }

    $dbTheme = settings_get('theme.current', null);
    if ($dbTheme) {
        $dbTheme = preg_replace('~[^a-z0-9\-_]~i', '', $dbTheme);
        if ($dbTheme !== '' && theme_exists($dbTheme)) {
            return $cached = $dbTheme;
        }
    }

    if (!empty($_COOKIE['theme_preview'])) {
        $t = preg_replace('~[^a-z0-9\-_]~i', '', (string)$_COOKIE['theme_preview']);
        if ($t !== '' && theme_exists($t)) {
            return $cached = $t;
        }
    }

    return $cached = ($cfgTheme ?: 'thorium-default');
}

// Theme path and URL utilities
function theme_path(string $rel = ''): string {
    return rtrim(THEME_DIR . '/' . theme_current() . '/' . ltrim($rel, '/'), '/');
}

function theme_web_base(): string {
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $base   = rtrim(dirname($script), '/');
    if ($base === '/' || $base === '\\') $base = '';
    return $base;
}

function theme_asset_url(string $rel): string {
    $rel  = ltrim($rel, '/');
    $slug = theme_current();
    $cfg  = $GLOBALS['config'] ?? [];
    $qs   = 't=' . rawurlencode($slug) . '&f=' . rawurlencode($rel);

    if (!empty($cfg['app_url'])) {
        return rtrim($cfg['app_url'], '/') . '/theme-asset.php?' . $qs;
    }
    return theme_web_base() . '/theme-asset.php?' . $qs;
}

// Asset management with versioning
function theme_asset_pick(string ...$candidates): string {
    foreach ($candidates as $rel) {
        $path = theme_path('public/' . ltrim($rel, '/'));
        if (is_file($path)) {
            $url = theme_asset_url($rel);
            $v   = @filemtime($path) ?: time();
            return $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . $v;
        }
    }
    return theme_asset_url($candidates[0] ?? '');
}

function theme_style_url(): string  { return theme_asset_pick('css/theme.css', 'theme.css'); }
function theme_script_url(): string { return theme_asset_pick('js/theme.js',  'theme.js');  }

// Template path resolution with fallback hierarchy
function themed_partial_path(string $name): string {
    $candidates = [
        theme_path("partials/$name.php"),
        ROOT_DIR . "/partials/$name.php",
        __DIR__   . "/../partials/$name.php",
        __DIR__   . "/../$name.php",
    ];
    foreach ($candidates as $p) if (is_file($p)) return $p;

    return ROOT_DIR . "/partials/$name.php";
}

function themed_page_path(string $name): string {
    $name = trim($name, '/');

    static $reserved = ['admin','login','logout','register','forgot','reset','forgot_username','panel'];
    $rootPages = ROOT_DIR . '/pages';
    $appPages  = __DIR__   . '/pages';

    if (in_array($name, $reserved, true)) {
        foreach (["$appPages/$name.php", "$rootPages/$name.php"] as $p) {
            if (is_file($p)) return $p;
        }
    } else {
        foreach ([
            theme_path("pages/$name.php"),
            "$rootPages/$name.php",
            "$appPages/$name.php",
            __DIR__ . "/../$name.php",
        ] as $p) {
            if (is_file($p)) return $p;
        }
    }

    foreach ([
        "$rootPages/404.php",
        theme_path('pages/404.php'),
        "$appPages/404.php",
        "$rootPages/home.php",
    ] as $p) {
        if (is_file($p)) return $p;
    }

    return "$rootPages/404.php";
}
