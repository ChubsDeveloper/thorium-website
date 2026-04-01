<?php
/**
 * Bootstrap file - Initializes the application framework and autoloader
 * Sets up constants, autoloading, configuration, and global helper functions
 */
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', __DIR__);
}
if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', dirname(__DIR__));
}

// Autoloader for App namespace classes
spl_autoload_register(function ($class) {
    if (strpos($class, 'App\\') !== 0) {
        return;
    }
    
    $path = APP_ROOT . '/' . str_replace(['App\\', '\\'], ['', '/'], $class) . '.php';
    if (file_exists($path)) {
        require_once $path;
    }
});

// Load configuration and initialize application
$config_file = APP_ROOT . '/config.php';
$config = is_file($config_file) ? require $config_file : [];

use App\Core\Application;
$app = Application::getInstance($config);

// Store app and config in globals for easy access
$GLOBALS['app'] = $app;
$GLOBALS['config'] = $config;

// Global helper functions for application access
if (!function_exists('app')) {
    function app(): Application {
        return $GLOBALS['app'];
    }
}

if (!function_exists('get_clean_app')) {
    function get_clean_app(): Application {
        return $GLOBALS['app'];
    }
}

// Repository helper functions with lazy loading
if (!function_exists('get_clean_module_repo')) {
    function get_clean_module_repo() {
        static $repo = null;
        if ($repo === null) {
            $repo = new \App\Repositories\modules_repository(get_clean_app());
        }
        return $repo;
    }
}

if (!function_exists('get_clean_settings_repo')) {
    function get_clean_settings_repo() {
        static $repo = null;
        if ($repo === null) {
            $repo = new \App\Repositories\settings_repository(get_clean_app());
        }
        return $repo;
    }
}

if (!function_exists('get_clean_theme_manager')) {
    function get_clean_theme_manager() {
        return get_clean_app()->getThemeManager();
    }
}

// Module management helper functions
if (!function_exists('clean_module_enabled')) {
    function clean_module_enabled(string $name): bool {
        return get_clean_module_repo()->is_enabled($name);
    }
}

if (!function_exists('clean_module_set_enabled')) {
    function clean_module_set_enabled(string $name, bool $enabled): void {
        get_clean_module_repo()->set_enabled($name, $enabled);
    }
}

// Settings management helper functions
if (!function_exists('clean_settings_get')) {
    function clean_settings_get(string $key, ?string $default = null): ?string {
        return get_clean_settings_repo()->get($key, $default);
    }
}

if (!function_exists('clean_settings_set')) {
    function clean_settings_set(string $key, string $value): void {
        get_clean_settings_repo()->set($key, $value);
    }
}

// Theme management helper functions
if (!function_exists('clean_theme_current')) {
    function clean_theme_current(): string {
        return get_clean_theme_manager()->getCurrentTheme();
    }
}

if (!function_exists('clean_theme_path')) {
    function clean_theme_path(string $relativePath = ''): string {
        return get_clean_theme_manager()->getThemePath($relativePath);
    }
}

if (!function_exists('clean_theme_asset_url')) {
    function clean_theme_asset_url(string $relativePath): string {
        return get_clean_theme_manager()->getThemeUrl($relativePath);
    }
}

// Mark clean system as loaded and return application instance
$GLOBALS['CLEAN_SYSTEM_LOADED'] = true;

return $app;
