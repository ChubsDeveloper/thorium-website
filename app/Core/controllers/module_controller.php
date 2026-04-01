<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Application;
use App\Services\ModuleService;

class ModuleController
{
    private Application $app;
    private ModuleService $moduleService;

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->moduleService = new ModuleService($app);
    }

    public function toggle(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        
        try {
            $this->app->getAuth()->requireAdmin();
            $this->app->getCsrf()->requireValidToken();
            
            $data = $this->parseRequest();
            $name = $data['name'] ?? '';
            $enabled = (bool)($data['enabled'] ?? false);
            
            if (empty($name)) {
                $this->jsonError(400, 'Module name is required');
                return;
            }
            
            $result = $this->moduleService->toggle($name, $enabled);
            $this->jsonResponse($result);
            
        } catch (\Throwable $e) {
            $this->jsonResponse($this->app->handleError($e));
        }
    }

    public function getStatus(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        
        try {
            $states = $this->moduleService->getModuleStates();
            $known = $this->moduleService->getKnownModules();
            
            $this->jsonResponse([
                'ok' => true,
                'modules' => $states,
                'known' => $known
            ]);
            
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
