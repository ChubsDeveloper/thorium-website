<?php
/**
 * Core Application class - Singleton pattern for managing application state
 * Handles configuration, database connections, authentication, and theme management
 */
declare(strict_types=1);

namespace App\Core;

class Application
{
    private static ?self $instance = null;
    private array $config;
    private $db = null;
    private $auth = null;
    private $csrf = null;
    private $theme_manager = null;

    private function __construct(array $config = [])
    {
        $this->config = $config;
        $this->initializeSession();
    }

    // Singleton pattern implementation
    public static function getInstance(array $config = []): self
    {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    // Configuration access methods
    public function getConfig(?string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->config;
        }
        return $this->config[$key] ?? $default;
    }

    // Lazy-loaded service getters
    public function getDb()
    {
        if ($this->db === null) {
            $this->db = Database\connection::getInstance($this->config['db'] ?? []);
        }
        return $this->db;
    }

    public function getAuth()
    {
        if ($this->auth === null) {
            $this->auth = new Security\auth($this);
        }
        return $this->auth;
    }

    public function getCsrf()
    {
        if ($this->csrf === null) {
            $this->csrf = new Security\csrf();
        }
        return $this->csrf;
    }

    public function getThemeManager()
    {
        if ($this->theme_manager === null) {
            $this->theme_manager = new Theme\theme_manager($this);
        }
        return $this->theme_manager;
    }

    // Initialize session if not already active
    private function initializeSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
            session_start();
        }
    }

    // Error handling with debug mode consideration
    public function handleError(\Throwable $e): array
    {
        $is_dev = $this->config['debug'] ?? false;
        return [
            'ok' => false,
            'error' => $is_dev ? $e->getMessage() : 'Internal server error',
            'code' => $e->getCode() ?: 500
        ];
	}
}
