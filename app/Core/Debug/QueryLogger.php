<?php
declare(strict_types=1);

namespace App\Core\Debug;

use PDO;
use PDOStatement;

/**
 * Pretty query profiler/logger.
 *
 * - DDL (CREATE/ALTER/DROP/TRUNCATE/RENAME) => always logged in a readable block with stack.
 * - SLOW queries (elapsed >= slowMs) => logged in a readable block with params + stack.
 * - Sampling applies to SLOW queries only (DDL always logs).
 * - Skips trivial heartbeat/meta queries unless slow/error.
 *
 * Env (optional):
 *   DB_PROFILER_SLOW_MS   int   default 250
 *   DB_PROFILER_SAMPLE    int   default 1
 *   DB_PROFILER_STACK     bool  default true
 *   DB_PROFILER_LOG       path  default storage/logs/query.log (set in connection)
 */
final class QueryLogger
{
    private string $logFile;
    private int $sample;       // 1 = log all SLOW queries, 10 ≈ every 10th SLOW query
    private bool $withStack;
    private int $slowMs;
    private string $projectRoot;

    public function __construct(
        ?PDO $pdoForLog,   // not used (file-only sink keeps it simple/fast)
        string $logFile,
        bool $toTable,     // ignored
        int $sample = 1,
        bool $withStack = true,
        int $slowMs = 250
    ) {
        $this->logFile   = $logFile;
        $this->sample    = max(1, $sample);
        $this->withStack = $withStack;
        $this->slowMs    = max(1, (int)$slowMs);

        $root = dirname(__DIR__, 3); // project root
        $this->projectRoot = is_string($root) ? rtrim(str_replace('\\', '/', $root), '/') : '';
    }

    /* ---------------- core helpers ---------------- */

    private function kindOf(string $sql): string
    {
        $s = strtoupper(strtok(ltrim($sql), " \n\t\r("));
        return match ($s) {
            'SELECT' => 'select',
            'INSERT' => 'insert',
            'UPDATE' => 'update',
            'DELETE' => 'delete',
            'REPLACE'=> 'replace',
            'CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'RENAME' => 'ddl',
            'SHOW', 'DESCRIBE', 'EXPLAIN' => 'meta',
            default   => strtolower($s ?: 'other'),
        };
    }

    private function isDDL(string $sql): bool
    {
        return (bool)preg_match('/^\s*(CREATE|ALTER|DROP|TRUNCATE|RENAME)\b/i', $sql);
    }

    private function isTrivialPing(string $sql): bool
    {
        // Treat tiny “heartbeat” queries as trivial (unless slow/error)
        $s = trim(preg_replace('/\s+/', ' ', $sql));
        return $s === 'SELECT 1' || $s === 'SELECT 1 AS 1';
    }

    private function compact(string $sql): string
    {
        return preg_replace('/\s+/', ' ', trim($sql));
    }

    private function redactParams(array $params): array
    {
        // Redact common sensitive keys
        $sensitive = ['pass','password','passwd','secret','token','auth','apikey','api_key'];
        $out = [];
        foreach ($params as $k => $v) {
            $key = is_string($k) ? strtolower($k) : (string)$k;
            $val = $v;
            foreach ($sensitive as $needle) {
                if (str_contains($key, $needle)) { $val = '[REDACTED]'; break; }
            }
            // Avoid logging huge blobs
            if (is_string($val) && strlen($val) > 300) {
                $val = substr($val, 0, 300) . '…';
            }
            $out[$k] = $val;
        }
        return $out;
    }

    private function captureStack(bool $deep = false): array
    {
        if (!$this->withStack) return [null, []];

        $limit = $deep ? 40 : 15;
        $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $limit);

        $out = [];
        foreach ($bt as $f) {
            $file = $f['file'] ?? null;
            if (!$file) continue;
            if (str_contains($file, 'QueryLogger.php')) continue; // skip self
            $line = (int)($f['line'] ?? 0);
            $norm = str_replace('\\', '/', $file);
            if ($this->projectRoot && str_starts_with($norm, $this->projectRoot)) {
                $norm = ltrim(substr($norm, strlen($this->projectRoot)), '/');
            }
            $out[] = $norm . ':' . $line;
        }
        return [$out[0] ?? null, $out];
    }

    private function route(): string
    {
        $route = $_SERVER['REQUEST_METHOD'] ?? '';
        if ($route) $route .= ' ' . ($_SERVER['REQUEST_URI'] ?? '');
        return $route ?: '-';
    }

    private function userId(): string
    {
        return isset($_SESSION['user']['id']) ? (string)((int)$_SESSION['user']['id']) : '-';
    }

    private function ts(): string { return date('c'); }

    private function writeBlock(array $lines): void
    {
        $block = implode(PHP_EOL, $lines) . PHP_EOL;
        if ($this->logFile) {
            $dir = dirname($this->logFile);
            if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
            @file_put_contents($this->logFile, $block, FILE_APPEND | LOCK_EX);
        }
    }

    private function sampled(): bool
    {
        return $this->sample <= 1 || mt_rand(1, $this->sample) === 1;
    }

    /* ---------------- public API used by TimedPDO ---------------- */

    /**
     * Build & emit logs for a statement execution.
     * Called by TimedPDOStatement/TimedPDO after execution/exception.
     */
    public function logExecution(string $sql, array $params, int $elapsedMs, ?int $rowCount, ?string $error = null): void
    {
        $kind   = $this->kindOf($sql);
        $isDDL  = ($kind === 'ddl');
        $slow   = $elapsedMs >= $this->slowMs;

        // Skip trivial heartbeats unless slow or error
        if (!$isDDL && !$slow && !$error && $this->isTrivialPing($sql)) {
            return;
        }

        // Only sample SLOW (non-DDL) queries; DDL always logs
        if (!$isDDL && !$error && $slow && !$this->sampled()) {
            return;
        }

        $route = $this->route();
        $uid   = $this->userId();
        $sqlCompact = $this->compact($sql);
        $paramsRed  = $this->redactParams($params);
        [$fileLine, $stack] = $this->captureStack($isDDL || $slow);

        if ($isDDL) {
            $lines = [];
            $lines[] = '── DDL detected ' . str_repeat('─', 78);
            $lines[] = sprintf('When : %s  (%d ms)', $this->ts(), $elapsedMs);
            $lines[] = sprintf('Route: %s   User: %s', $route, $uid);
            $lines[] = sprintf('From : %s', $fileLine ?? '-');
            if ($stack) {
                $lines[] = 'Stack:';
                foreach (array_slice($stack, 0, 18) as $fr) { $lines[] = '  - ' . $fr; }
                if (count($stack) > 18) $lines[] = '  … (truncated)';
            }
            if ($error) $lines[] = 'Error: ' . $error;
            $lines[] = 'SQL:';
            $lines[] = $sqlCompact;
            $lines[] = str_repeat('─', 95);
            $this->writeBlock($lines);
            return;
        }

        if ($slow || $error) {
            $lines = [];
            $title = $error ? 'QUERY ERROR' : 'SLOW QUERY';
            $lines[] = '── ' . $title . ' ' . str_repeat('─', 86);
            $lines[] = sprintf('When : %s  (%d ms)%s', $this->ts(), $elapsedMs, $rowCount !== null ? "  Rows: {$rowCount}" : '');
            $lines[] = sprintf('Kind : %s', $kind);
            $lines[] = sprintf('Route: %s   User: %s', $route, $uid);
            $lines[] = sprintf('From : %s', $fileLine ?? '-');
            if ($stack) {
                $lines[] = 'Stack:';
                foreach (array_slice($stack, 0, 18) as $fr) { $lines[] = '  - ' . $fr; }
                if (count($stack) > 18) $lines[] = '  … (truncated)';
            }
            if ($error) $lines[] = 'Error: ' . $error;
            $lines[] = 'SQL:';
            $lines[] = $sqlCompact;
            if (!empty($paramsRed)) {
                $lines[] = 'Params: ' . json_encode($paramsRed, JSON_UNESCAPED_UNICODE);
            }
            $lines[] = str_repeat('─', 95);
            $this->writeBlock($lines);
        }
    }
}

/**
 * Statement decorator that times prepared executes.
 */
class TimedPDOStatement extends PDOStatement
{
    protected QueryLogger $logger;
    protected function __construct(QueryLogger $logger) { $this->logger = $logger; }

    public function execute(?array $params = null): bool
    {
        $sql    = $this->queryString ?? '';
        $start  = microtime(true);
        $err    = null;
        $rows   = null;

        try {
            $ret = parent::execute($params ?? []);
            try { $rows = $this->rowCount(); } catch (\Throwable) {}
            return (bool)$ret;
        } catch (\Throwable $e) {
            $err = $e->getMessage();
            throw $e;
        } finally {
            $elapsed = (int)round((microtime(true) - $start) * 1000);
            $this->logger->logExecution($sql, $params ?? [], $elapsed, $rows, $err);
        }
    }
}

/**
 * PDO subclass to time exec() and query() as well, with correct query() signature.
 */
class TimedPDO extends PDO
{
    private QueryLogger $logger;

    public function __construct(string $dsn, ?string $user, ?string $pass, array $options, QueryLogger $logger)
    {
        parent::__construct($dsn, $user ?? '', $pass ?? '', $options);
        $this->logger = $logger;
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [TimedPDOStatement::class, [$this->logger]]);
    }

    public function exec($statement): int|false
    {
        $sql   = (string)$statement;
        $start = microtime(true);
        $err   = null;
        try {
            $res = parent::exec($statement);
            return $res;
        } catch (\Throwable $e) {
            $err = $e->getMessage();
            throw $e;
        } finally {
            $elapsed = (int)round((microtime(true) - $start) * 1000);
            // rowCount is not available for exec reliably; pass null
            $this->logger->logExecution($sql, [], $elapsed, null, $err);
        }
    }

    /**
     * Correct signature: 2nd arg is ?int $fetchMode (PDO::FETCH_*).
     */
    public function query($statement, ?int $mode = null, ...$fetch_mode_args): PDOStatement|false
    {
        $sql   = (string)$statement;
        $start = microtime(true);
        $err   = null;
        try {
            if ($mode === null) {
                $stmt = parent::query($statement);
            } elseif (empty($fetch_mode_args)) {
                $stmt = parent::query($statement, $mode);
            } else {
                $stmt = parent::query($statement, $mode, ...$fetch_mode_args);
            }
            return $stmt;
        } catch (\Throwable $e) {
            $err = $e->getMessage();
            throw $e;
        } finally {
            $elapsed = (int)round((microtime(true) - $start) * 1000);
            // For raw query(), we don't have bound params at this point.
            $this->logger->logExecution($sql, [], $elapsed, null, $err);
        }
    }
}
