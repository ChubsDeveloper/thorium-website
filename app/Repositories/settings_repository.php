<?php
/**
 * Settings Repository
 * 
 * Key-value storage repository for application settings and configuration values.
 * Provides simple get/set interface with automatic table creation and timestamping.
 */
declare(strict_types=1);

namespace App\Repositories;

class settings_repository extends base_repository
{
    protected string $table = 'settings';

    /**
     * Initialize settings repository with database connection and table setup
     * 
     * @param mixed $app Application instance
     */
    public function __construct($app)
    {
        parent::__construct($app);
        $this->bootstrap();
    }

    /**
     * Get setting value by key
     * 
     * @param string $key Setting key
     * @param string|null $default Default value if key not found
     * @return string|null Setting value or default
     */
    public function get(string $key, ?string $default = null): ?string
    {
        $sql = "SELECT v FROM {$this->table} WHERE k = ? LIMIT 1";
        $value = $this->fetch_column($sql, [$key]);
        return $value !== false ? (string)$value : $default;
    }

    /**
     * Set setting value by key
     * 
     * @param string $key Setting key
     * @param string $value Setting value
     */
    public function set(string $key, string $value): void
    {
        $sql = "INSERT INTO {$this->table} (k, v) VALUES (?, ?) 
                ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = CURRENT_TIMESTAMP";
        $this->execute($sql, [$key, $value]);
    }

    /**
     * Create settings table if it doesn't exist
     */
    private function bootstrap(): void
    {
        $schema = "
            CREATE TABLE IF NOT EXISTS {$this->table} (
                k VARCHAR(64) NOT NULL PRIMARY KEY,
                v TEXT NOT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";
        $this->create_table_if_not_exists($schema);
    }
}
