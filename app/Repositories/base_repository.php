<?php
/**
 * Base Repository Class
 * 
 * Abstract base class providing common database operations, query caching,
 * performance monitoring, and safety utilities for all repository classes.
 * Includes prepared statement caching, slow query logging, and transaction support.
 */
declare(strict_types=1);

namespace App\Repositories;

use PDO;
use PDOStatement;

abstract class base_repository
{
    protected $app;
    protected PDO $pdo;
    protected string $table = '';

    protected array $stmtCache = [];
    protected int $slowMs = 200;

    /**
     * Initialize repository with application instance and database connection
     * 
     * @param mixed $app Application instance
     */
    public function __construct($app)
{
    $this->app = $app;

    // 🔓 Release PHP session lock for chat GET endpoints so auth/login isn’t blocked
    // (safe: only GETs; POSTs still hold the lock)
    static $sessionUnlocked = false;
    if (!$sessionUnlocked && PHP_SAPI !== 'cli') {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $script = $_SERVER['SCRIPT_NAME']   ?? $_SERVER['PHP_SELF'] ?? '';
        $path   = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';

        // Match /api/chat-*.php (or chat-*.php) when the request is GET
        $isChatGet =
            $method === 'GET' && (
                preg_match('~/(?:api/)?chat-[\w\-]+\.php$~i', $script) ||
                preg_match('~/(?:api/)?chat-[\w\-]+(?:\.php)?$~i', $path)
            );

        if ($isChatGet) {
            try {
                if (session_status() === \PHP_SESSION_ACTIVE) {
                    // We already have a session; close it to release the lock.
                    @session_write_close();
                } elseif (!headers_sent() && isset($_COOKIE[session_name()])) {
                    // If a session cookie exists but no session started yet,
                    // start in read-only mode so it immediately unlocks.
                    @session_start(['read_and_close' => true]);
                }
            } catch (\Throwable $__) {
                // swallow — better to fail open than crash
            }
            $sessionUnlocked = true;
        }
    }

    // your existing DB wire-up
    $this->pdo = $app->getDb()->getPdo();
}


    /**
     * Set threshold for slow query logging
     * 
     * @param int $ms Threshold in milliseconds (minimum 0)
     */
    public function setSlowThreshold(int $ms): void
    {
        $this->slowMs = max(0, $ms);
    }

    /**
     * Execute SQL query with prepared statement caching and performance monitoring
     * 
     * @param string $sql SQL query with parameter placeholders
     * @param array $params Query parameters
     * @return PDOStatement Executed statement ready for fetching
     */
    protected function execute(string $sql, array $params = []): PDOStatement
    {
        $start = microtime(true);

        // Use cached prepared statement or create new one
        if (!isset($this->stmtCache[$sql])) {
            $this->stmtCache[$sql] = $this->pdo->prepare($sql);
        }

        $stmt = $this->stmtCache[$sql];
        $stmt->closeCursor();

        $stmt->execute($params);

        // Log slow queries if threshold is exceeded
        $elapsedMs = (int)round((microtime(true) - $start) * 1000);
        if ($this->slowMs > 0 && $elapsedMs >= $this->slowMs) {
            error_log(sprintf(
                '[SQL SLOW %dms] %s | params=%s',
                $elapsedMs, $this->compactSql($sql), json_encode($params, JSON_UNESCAPED_UNICODE)
            ));
        }

        return $stmt;
    }

    /**
     * Compact SQL string for logging by removing extra whitespace
     * 
     * @param string $sql Original SQL query
     * @return string Compacted SQL query
     */
    protected function compactSql(string $sql): string
    {
        return preg_replace('/\s+/', ' ', trim($sql));
    }

    /**
     * Fetch single row from database
     * 
     * @param string $sql SQL query
     * @param array $params Query parameters
     * @return array|null Row data or null if not found
     */
    protected function fetch_one(string $sql, array $params = []): ?array
    {
        $stmt = $this->execute($sql, $params);
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        return $row !== false ? $row : null;
    }

    /**
     * Fetch all rows from database
     * 
     * @param string $sql SQL query
     * @param array $params Query parameters
     * @return array Array of rows (empty array if no results)
     */
    protected function fetch_all(string $sql, array $params = []): array
    {
        $stmt = $this->execute($sql, $params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        return $rows ?: [];
    }

    /**
     * Fetch single column value from first row
     * 
     * @param string $sql SQL query
     * @param array $params Query parameters
     * @return mixed Column value or false if no results
     */
    protected function fetch_column(string $sql, array $params = [])
    {
        $stmt = $this->execute($sql, $params);
        $val  = $stmt->fetchColumn();
        $stmt->closeCursor();
        return $val;
    }

    /**
     * Fetch rows as iterator for memory-efficient processing of large datasets
     * 
     * @param string $sql SQL query
     * @param array $params Query parameters
     * @return \Generator Iterator yielding rows one at a time
     */
    protected function fetch_iter(string $sql, array $params = []): \Generator
    {
        $stmt = $this->execute($sql, $params);
        try {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                yield $row;
            }
        } finally {
            $stmt->closeCursor();
        }
    }

    /**
     * Fetch paginated results with limit and offset
     * 
     * @param string $sql Base SQL query (without LIMIT clause)
     * @param array $params Query parameters
     * @param int $limit Maximum number of rows to return
     * @param int $offset Number of rows to skip
     * @return array Array of rows for the requested page
     */
    protected function fetch_page(string $sql, array $params, int $limit, int $offset = 0): array
    {
        $limit  = $this->require_limit($limit);
        $offset = max(0, $offset);

        $sql .= ' LIMIT :__limit OFFSET :__offset';
        $params[':__limit']  = $limit;
        $params[':__offset'] = $offset;

        return $this->fetch_all($sql, $params);
    }

    /**
     * Execute DDL schema creation if auto-DDL is enabled
     * 
     * @param string $schema CREATE TABLE or other DDL statement
     */
    protected function create_table_if_not_exists(string $schema): void
    {
        try {
            $enabled = $this->resolveDdlEnabled();

            if (!$enabled) {
                $where = $this->shortCaller();
                $one   = $this->firstLine($schema);
                error_log("[DDL SKIPPED] {$where} :: {$one}");
                return;
            }
            $this->pdo->exec($schema);
        } catch (\Throwable $e) {
            // Silently ignore DDL errors (table might already exist)
        }
    }

    /**
     * Sanitize input to contain only alphanumeric characters, hyphens, and underscores
     * 
     * @param string $input Raw input string
     * @return string Sanitized string
     */
    protected function sanitize_alphanumeric(string $input): string
    {
        return preg_replace('/[^a-z0-9\-_]/i', '', $input);
    }

    /**
     * Validate and enforce reasonable limits for database queries
     * 
     * @param int $limit Requested limit
     * @param int $max Maximum allowed limit
     * @return int Validated limit
     * @throws \InvalidArgumentException When limit is invalid
     */
    protected function require_limit(int $limit, int $max = 500): int
    {
        if ($limit <= 0) {
            throw new \InvalidArgumentException('LIMIT must be > 0');
        }
        if ($limit > $max) {
            throw new \InvalidArgumentException("LIMIT too large (max {$max})");
        }
        return $limit;
    }

    /**
     * Safety check to prevent UPDATE/DELETE queries without WHERE clauses
     * 
     * @param string $sql SQL query to validate
     * @param string $targetTable Table name being modified
     * @throws \RuntimeException When unsafe query is detected
     */
    protected function require_where(string $sql, string $targetTable): void
    {
        $sqlNorm = strtolower($this->compactSql($sql));
        if (
            ($this->starts_with($sqlNorm, 'update') || $this->starts_with($sqlNorm, 'delete')) &&
            str_contains($sqlNorm, strtolower($targetTable)) &&
            !str_contains($sqlNorm, ' where ')
        ) {
            throw new \RuntimeException('Refusing to run UPDATE/DELETE without WHERE on ' . $targetTable);
        }
    }

    /**
     * Check if string starts with given prefix (case-sensitive)
     * 
     * @param string $haystack String to search in
     * @param string $needle Prefix to look for
     * @return bool True if haystack starts with needle
     */
    protected function starts_with(string $haystack, string $needle): bool
    {
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }

    /** Begin database transaction if not already active */
    protected function begin(): void   { if (!$this->pdo->inTransaction()) $this->pdo->beginTransaction(); }
    
    /** Commit current transaction if active */
    protected function commit(): void  { if ($this->pdo->inTransaction())  $this->pdo->commit(); }
    
    /** Roll back current transaction if active */
    protected function rollBack(): void{ if ($this->pdo->inTransaction())  $this->pdo->rollBack(); }
    
    /** Check if a transaction is currently active */
    protected function inTransaction(): bool { return $this->pdo->inTransaction(); }

    /**
     * Get the ID of the last inserted row
     * 
     * @return string Last insert ID
     */
    protected function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }

    /**
     * Determine if automatic DDL execution is enabled
     * Checks constants, app config, and environment variables
     * 
     * @return bool True if DDL should be executed automatically
     */
    private function resolveDdlEnabled(): bool
    {
        if (defined('DB_AUTO_DDL')) {
            return (bool)DB_AUTO_DDL;
        }

        try {
            if (is_object($this->app) && method_exists($this->app, 'config')) {
                $cfg = $this->app->config('db.auto_ddl', null);
                if ($cfg !== null) {
                    return (bool)$cfg;
                }
            }
        } catch (\Throwable) { /* ignore config errors */ }

        $raw = $_ENV['DB_AUTO_DDL'] ?? getenv('DB_AUTO_DDL');
        return $raw === null || $raw === '' ? true : (bool)filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Get caller information for debugging (excludes base_repository.php)
     * 
     * @return string Caller file:line or 'unknown'
     */
    private function shortCaller(): string
    {
        $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8);
        foreach ($bt as $f) {
            $file = $f['file'] ?? null;
            if (!$file) continue;
            if (!str_contains($file, 'base_repository.php')) {
                $line = (int)($f['line'] ?? 0);
                return basename($file) . ':' . $line;
            }
        }
        return 'unknown';
    }

    /**
     * Get first line of SQL for logging (truncated to 160 characters)
     * 
     * @param string $sql SQL query
     * @return string Truncated first line
     */
    private function firstLine(string $sql): string
    {
        $one = preg_replace('/\s+/', ' ', trim($sql));
        return mb_substr($one, 0, 160);
    }
}
