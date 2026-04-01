<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Application;
use App\Core\Theme\ThemeManager;

class ThemeService
{
    private Application $app;
    private ThemeManager $themeManager;

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->themeManager = $app->getThemeManager();
    }

    public function getCurrentTheme(): string
    {
        return $this->themeManager->getCurrentTheme();
    }

    public function setTheme(string $theme): array
    {
        try {
            $config = $this->app->getConfig();
            
            if (!empty($config['theme_lock'])) {
                return [
                    'ok' => false,
                    'error' => 'Theme is locked by configuration'
                ];
            }

            if (!$this->themeManager->themeExists($theme)) {
                return [
                    'ok' => false,
                    'error' => 'Theme does not exist'
                ];
            }

            $success = $this->themeManager->setTheme($theme);
            if ($success) {
                return ['ok' => true, 'theme' => $theme];
            } else {
                return ['ok' => false, 'error' => 'Failed to set theme'];
            }
        } catch (\Throwable $e) {
            return $this->app->handleError($e);
        }
    }

    public function getAvailableThemes(): array
    {
        return $this->themeManager->getAvailableThemes();
    }

    public function isLocked(): bool
    {
        return !empty($this->app->getConfig('theme_lock'));
    }

    public function getThemeConfig(): array
    {
        return [
            'current' => $this->getCurrentTheme(),
            'available' => $this->getAvailableThemes(),
            'locked' => $this->isLocked()
        ];
    }
}
