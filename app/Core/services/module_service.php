<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Application;
use App\Repositories\ModuleRepository;

class ModuleService
{
    private Application $app;
    private ModuleRepository $repository;

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->repository = new ModuleRepository($app);
    }

    public function isEnabled(string $name): bool
    {
        return $this->repository->isEnabled($name);
    }

    public function toggle(string $name, bool $enabled): array
    {
        try {
            $this->repository->setEnabled($name, $enabled);
            return ['ok' => true, 'name' => $name, 'enabled' => $enabled];
        } catch (\Throwable $e) {
            return $this->app->handleError($e);
        }
    }

    public function getAllModules(): array
    {
        return $this->repository->getAllModules();
    }

    public function getKnownModules(): array
    {
        return [
            ['name' => 'news', 'label' => 'News', 'desc' => 'Stacked posts on the home page.'],
            ['name' => 'bloodmarks', 'label' => 'Bloodmarks', 'desc' => 'Bloodmarking leaderboard card.'],
            ['name' => 'realms', 'label' => 'Realm Status', 'desc' => 'Live status and population widgets.'],
            ['name' => 'discord', 'label' => 'Discord', 'desc' => 'Discord CTA and/or embedded widget.'],
            ['name' => 'armory', 'label' => 'Armory', 'desc' => 'Character lookup page + tooltip.'],
            ['name' => 'chat', 'label' => 'Live Chat', 'desc' => 'Real-time chat system for players.'],
            ['name' => 'header_chat', 'label' => 'Header Chat', 'desc' => 'Compact chat widget in header.'],
        ];
    }

    public function getModuleStates(): array
    {
        $states = [];
        $known = $this->getKnownModules();
        $dbModules = $this->repository->getAllModules();
        
        foreach ($known as $module) {
            $states[$module['name']] = $this->repository->isEnabled($module['name']);
        }
        
        foreach ($dbModules as $module) {
            if (!isset($states[$module['name']])) {
                $states[$module['name']] = (bool)$module['enabled'];
            }
        }
        
        return $states;
    }

    public function renderModule(string $name, array $variables = []): void
    {
        if (!$this->isEnabled($name)) {
            return;
        }
        
        $this->app->getThemeManager()->renderPartial("modules/{$name}", $variables);
    }
}
