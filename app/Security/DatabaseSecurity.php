<?php
declare(strict_types=1);

namespace App\Security;

/**
 * DatabaseSecurity - Best practices for database security
 * Provides utilities for safe database operations and query validation
 */
class DatabaseSecurity
{
    /**
     * Validate that a query uses parameterized statements (has placeholders)
     * This is a safety check - actual parameterization is enforced by PDO prepare/execute
     */
    public static function isParameterized(string $query): bool
    {
        // Check for ? or :param style placeholders
        return (bool)preg_match('/[\?:]\w+/', $query);
    }

    /**
     * Sanitize table/column names (for dynamic queries where parameterization isn't possible)
     * This is NOT a substitute for prepared statements - only use for identifiers
     */
    public static function sanitizeIdentifier(string $identifier): string
    {
        // Only allow alphanumeric, underscore, and backticks
        if (!preg_match('/^`?[a-zA-Z0-9_]+`?$/', $identifier)) {
            throw new \InvalidArgumentException("Invalid identifier: {$identifier}");
        }

        // Add backticks if not present
        return '`' . trim($identifier, '`') . '`';
    }

    /**
     * Build safe IN clause for use with prepared statements
     * Usage: $stmt->execute($ids); and use placeholders in query like WHERE id IN (?, ?, ?)
     */
    public static function buildInClause(array $values, int $maxValues = 1000): string
    {
        if (empty($values)) {
            throw new \InvalidArgumentException("IN clause requires at least one value");
        }

        if (count($values) > $maxValues) {
            throw new \InvalidArgumentException("Too many values for IN clause (max: {$maxValues})");
        }

        // Create placeholder string: ?, ?, ?
        return implode(', ', array_fill(0, count($values), '?'));
    }

    /**
     * Validate database connection security
     */
    public static function validateConnection(\PDO $pdo): bool
    {
        try {
            // Test connection
            $stmt = $pdo->query('SELECT 1');
            if (!$stmt) {
                return false;
            }

            // Verify prepared statements are not emulated (for security)
            $attr = $pdo->getAttribute(\PDO::ATTR_EMULATE_PREPARES);
            if ($attr !== false) {
                error_log('Warning: PDO is emulating prepared statements. This can be less secure.');
            }

            return true;
        } catch (\Exception $e) {
            error_log('Database connection validation failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Escape single quotes in strings (additional layer, but prepared statements are preferred)
     */
    public static function escapeSingleQuotes(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    /**
     * Validate column name exists in table before using dynamically
     */
    public static function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        try {
            $stmt = $pdo->prepare("DESCRIBE " . self::sanitizeIdentifier($table));
            $stmt->execute();
            $columns = $stmt->fetchAll();

            foreach ($columns as $col) {
                if ($col['Field'] === $column) {
                    return true;
                }
            }

            return false;
        } catch (\Exception $e) {
            error_log("Error checking column existence: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get safe database info for logging (strips credentials)
     */
    public static function getDatabaseInfo(\PDO $pdo): array
    {
        try {
            return [
                'driver' => $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME),
                'connection_status' => 'connected',
                'server_version' => $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION),
            ];
        } catch (\Exception $e) {
            return ['error' => 'Unable to retrieve database info'];
        }
    }

    /**
     * Log database query (with sensitive data stripped)
     */
    public static function logQuery(string $query, array $params = []): void
    {
        // Remove sensitive patterns
        $sanitizedQuery = preg_replace('/password\s*=\s*[\'"]?[^\s,)\'";]*[\'"]?/i', 'password=[REDACTED]', $query);
        $sanitizedQuery = preg_replace('/secret\s*[=:]\s*[\'"]?[^\s,)\'";]*[\'"]?/i', 'secret=[REDACTED]', $sanitizedQuery);

        // Limit params logging
        $sanitizedParams = array_map(function($v) {
            if (is_string($v) && strlen($v) > 100) {
                return substr($v, 0, 100) . '...';
            }
            if (strpos((string)$v, 'password') !== false || strpos((string)$v, 'secret') !== false) {
                return '[REDACTED]';
            }
            return $v;
        }, $params);

        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] Query: {$sanitizedQuery} | Params: " . json_encode($sanitizedParams) . "\n";

        $logDir = dirname(__DIR__, 1) . '/storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0750, true);
        }

        @file_put_contents($logDir . '/database.log', $logEntry, FILE_APPEND | LOCK_EX);
    }
}
