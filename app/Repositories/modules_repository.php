<?php
/**
 * Modules Repository
 * 
 * Manages module enable/disable state and provides module configuration storage.
 * Handles dynamic module activation with persistent storage and automatic table creation.
 */
declare(strict_types=1);

namespace App\Repositories;

class modules_repository extends base_repository
{
    protected string $table = 'modules';

    /**
     * Initialize modules repository with database connection and table setup
     * 
     * @param mixed $app Application instance
     */
    public function __construct($app)
    {
        parent::__construct($app);
        $this->bootstrap();
    }

    /**
     * Check if a module is enabled
     * 
     * @param string $name Module name (sanitized to alphanumeric)
     * @return bool True if module is enabled
     */
    public function is_enabled(string $name): bool
    {
        $name = $this->sanitize_alphanumeric($name);
        if (empty($name)) return false;

        $sql = "SELECT enabled FROM {$this->table} WHERE name = ? LIMIT 1";
        $enabled = $this->fetch_column($sql, [$name]);
        return (bool)$enabled;
    }

    /**
     * Enable or disable a module
     * 
     * @param string $name Module name
     * @param bool $enabled Whether module should be enabled
     * @throws \InvalidArgumentException When module name is invalid
     */
    public function set_enabled(string $name, bool $enabled): void
    {
        $name = $this->sanitize_alphanumeric($name);
        if (empty($name)) {
            throw new \InvalidArgumentException('Invalid module name');
        }

        $sql = "INSERT INTO {$this->table} (name, enabled) VALUES (?, ?) 
                ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_at = CURRENT_TIMESTAMP";
        $this->execute($sql, [$name, $enabled ? 1 : 0]);
    }

    /**
     * Get all modules with their enabled status
     * 
     * @return array Array of modules with name and enabled status
     */
    public function get_all_modules(): array
    {
        $sql = "SELECT name, enabled FROM {$this->table} ORDER BY name";
        return $this->fetch_all($sql);
    }

    /**
     * Create modules table if it doesn't exist
     */
    private function bootstrap(): void
    {
        $schema = "
            CREATE TABLE IF NOT EXISTS {$this->table} (
                name VARCHAR(64) NOT NULL PRIMARY KEY,
                enabled TINYINT(1) NOT NULL DEFAULT 0,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_enabled (enabled)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";
        $this->create_table_if_not_exists($schema);
    }
}
