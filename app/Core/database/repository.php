<?php
declare(strict_types=1);

namespace App\Core\Database;

use App\Core\Application;
use PDO;
use PDOStatement;

abstract class Repository
{
    protected Application $app;
    protected PDO $pdo;
    protected string $table;

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->pdo = $app->getDb()->getPdo();
    }

    protected function execute(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    protected function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->execute($sql, $params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    protected function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->execute($sql, $params);
        return $stmt->fetchAll();
    }

    protected function fetchColumn(string $sql, array $params = [])
    {
        $stmt = $this->execute($sql, $params);
        return $stmt->fetchColumn();
    }

    protected function exists(string $column, $value): bool
    {
        $sql = "SELECT 1 FROM {$this->table} WHERE {$column} = ? LIMIT 1";
        return (bool) $this->fetchColumn($sql, [$value]);
    }

    protected function hasColumn(string $column): bool
    {
        $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = ? 
                AND COLUMN_NAME = ? 
                LIMIT 1";
        return (bool) $this->fetchColumn($sql, [$this->table, $column]);
    }

    protected function createTableIfNotExists(string $schema): void
    {
        try {
            $this->pdo->exec($schema);
        } catch (\Throwable $e) {
            // Table might already exist, ignore
        }
    }

    protected function sanitizeAlphanumeric(string $input): string
    {
        return preg_replace('/[^a-z0-9\-_]/i', '', $input);
    }

    protected function validateRequired(array $data, array $required): void
    {
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new \InvalidArgumentException("Required field missing: {$field}");
            }
        }
    }
}
