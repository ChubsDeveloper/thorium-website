<?php
declare(strict_types=1);

namespace App\Core\Theme;

class theme_manager
{
    private $app;
    private ?string $current_theme = null;
    private string $themes_dir;

    public function __construct($app)
    {
        $this->app = $app;
        $this->themes_dir = dirname(__DIR__, 3) . '/themes';
    }

    public function getCurrentTheme(): string
    {
        if ($this->current_theme !== null) {
            return $this->current_theme;
        }

        $config = $this->app->getConfig();
        $config_theme = $config['theme'] ?? null;
        $locked = !empty($config['theme_lock']);

        if ($locked && $config_theme) {
            return $this->current_theme = preg_replace('/[^a-z0-9\-_]/i', '', $config_theme);
        }

        if (!empty($_GET['theme'])) {
            $t = preg_replace('/[^a-z0-9\-_]/i', '', $_GET['theme']);
            if ($t !== '' && $this->theme_exists($t)) {
                if (!headers_sent()) {
                    setcookie('theme_preview', $t, time() + 3600, '/');
                }
                return $this->current_theme = $t;
            }
        }

        return $this->current_theme = $config_theme ?: 'thorium-default';
    }

    public function getThemePath(string $rel = ''): string
    {
        $theme = $this->getCurrentTheme();
        return rtrim($this->themes_dir . '/' . $theme . '/' . ltrim($rel, '/'), '/');
    }

    public function getThemeUrl(string $rel = ''): string
    {
        $theme = $this->getCurrentTheme();
        $base = $this->get_web_base();
        return $base . '/theme-asset.php?t=' . urlencode($theme) . '&f=' . urlencode($rel);
    }

    private function theme_exists(string $theme): bool
    {
        $safe = preg_replace('/[^a-z0-9\-_]/i', '', $theme);
        return $safe !== '' && is_dir($this->themes_dir . '/' . $safe);
    }

    private function get_web_base(): string
    {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
        if (strpos($script, '/public/') !== false) {
            return '/public';
        }
        return '';
    }
}
