<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Application;
use App\Services\ThemeService;

class ThemeController
{
    private Application $app;
    private ThemeService $themeService;

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->themeService = new ThemeService($app);
    }

    public function setTheme(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        
        try {
            $this->app->getAuth()->requireAdmin();
            $this->app->getCsrf()->requireValidToken();
            
            $data = $this->parseRequest();
            $theme = $data['theme'] ?? '';
            
            if (empty($theme)) {
                $this->jsonError(400, 'Theme name is required');
                return;
            }
            
            $result = $this->themeService->setTheme($theme);
            $this->jsonResponse($result);
            
        } catch (\Throwable $e) {
            $this->jsonResponse($this->app->handleError($e));
        }
    }

    public function getConfig(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        
        try {
            $config = $this->themeService->getThemeConfig();
            $this->jsonResponse(['ok' => true, 'config' => $config]);
            
        } catch (\Throwable $e) {
            $this->jsonResponse($this->app->handleError($e));
        }
    }

    private function parseRequest(): array
    {
        $raw = file_get_contents('php://input') ?: '{}';
        $data = json_decode($raw, true);
        return is_array($data) ? $data : $_POST;
    }

    private function jsonResponse(array $data): void
    {
        echo json_encode($data, JSON_UNESCAPED_SLASHES);
    }

    private function jsonError(int $code, string $message): void
    {
        http_response_code($code);
        $this->jsonResponse(['ok' => false, 'error' => $message]);
    }
}
