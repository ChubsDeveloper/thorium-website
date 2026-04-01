<?php
/**
 * Database Connection Manager
 * 
 * Singleton pattern database connection handler with retry logic, profiling support,
 * and connection health monitoring. Manages PDO connections with configurable options
 * for timeout, compression, persistence, and query logging.
 */
declare(strict_types=1);

namespace App\Core\Database;

use PDO;
use PDOException;
use RuntimeException;

use App\Core\Debug\QueryLogger;
use App\Core\Debug\TimedPDO;

final class connection
{
    private static ?self $instance = null;
    private ?PDO $pdo = null;
    private array $config;

    /**
     * Initialize database configuration with default values and environment overrides
     * 
     * @param array $config Database configuration parameters
     */
    private function __construct(array $config)
    {
        $this->config = array_merge([
            'host'       => '127.0.0.1',
            'port'       => 3306,
            'name'       => 'thorium_website',
            'user'       => 'ChubsDev',
            'pass'       => 'chubsdev1141',
            'charset'    => 'utf8mb4',
            'collation'  => 'utf8mb4_general_ci',
            'persistent' => true,
            'compress'   => true,
            'timeout'    => 5,
            'retries'    => 1,

            'profiler'        => filter_var($_ENV['DB_PROFILER'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'profiler_table'  => filter_var($_ENV['DB_PROFILER_TABLE'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'profiler_sample' => (int)($_ENV['DB_PROFILER_SAMPLE'] ?? 1),
            'profiler_stack'  => filter_var($_ENV['DB_PROFILER_STACK'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'profiler_log'    => (string)($_ENV['DB_PROFILER_LOG'] ?? (dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'query.log')),
            'profiler_file'   => (string)($_ENV['DB_PROFILER_FILE'] ?? (dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'Debug' . DIRECTORY_SEPARATOR . 'QueryProfiler.php')),
        ], $config);
    }

    /**
     * Get singleton database connection instance
     * 
     * @param array $config Configuration array (required on first call)
     * @return self Database connection instance
     * @throws RuntimeException When configuration is missing on first call
     */
    public static function getInstance(array $config = []): self
    {
        if (self::$instance === null) {
            if (empty($config)) {
                throw new RuntimeException('Database configuration required on first call');
            }
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    /**
     * Get PDO connection with health check and auto-reconnect
     * 
     * @return PDO Active database connection
     */
    public function getPdo(): PDO
    {
        if ($this->pdo === null) {
            $this->connectWithRetry();
        } else {
            // Test connection health and reconnect if needed
            try { $this->pdo->query('SELECT 1'); } catch (\Throwable) { $this->connectWithRetry(); }
        }
        return $this->pdo;
    }

    /**
     * Attempt database connection with retry logic and exponential backoff
     * 
     * @throws RuntimeException When all connection attempts fail
     */
    private function connectWithRetry(): void
    {
        $tries = max(1, (int)$this->config['retries'] + 1);
        $last  = null;

        while ($tries-- > 0) {
            try {
                $this->connect();
                return;
            } catch (PDOException $e) {
                $last = $e;
                usleep(150_000); // 150ms delay between attempts
            }
        }
        throw new RuntimeException('Database connection failed: ' . ($last?->getMessage() ?? 'unknown'));
    }

    /**
     * Establish database connection with profiling and optimization settings
     * 
     * @throws RuntimeException When required configuration is missing
     */
    private function connect(): void
    {
        // Validate required configuration parameters
        foreach (['host','name','user'] as $key) {
            if (empty($this->config[$key])) {
                throw new RuntimeException("Database config missing: {$key}");
            }
        }

        $profilerOn  = (bool)$this->config['profiler'];
        $profFile    = (string)$this->config['profiler_file'];

        if ($profilerOn) {
            if (is_file($profFile)) {
                require_once $profFile;
            }
            if (!class_exists(\App\Core\Debug\QueryLogger::class) || !class_exists(\App\Core\Debug\TimedPDO::class)) {
                $profilerOn = false;
            }
        }

        $port = $this->config['port'] ? ";port={$this->config['port']}" : '';
        $dsn  = "mysql:host={$this->config['host']}{$port};dbname={$this->config['name']};charset={$this->config['charset']}";

        $persistent = $profilerOn ? false : (bool)$this->config['persistent'];

        $options = [
            PDO::ATTR_ERRMODE                  => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE       => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT                  => (int)$this->config['timeout'],
            PDO::ATTR_PERSISTENT               => $persistent,
            PDO::MYSQL_ATTR_INIT_COMMAND       => "SET NAMES {$this->config['charset']} COLLATE {$this->config['collation']}",
            PDO::ATTR_EMULATE_PREPARES         => true,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ];

        if (!empty($this->config['compress']) && defined('PDO::MYSQL_ATTR_COMPRESS')) {
            $options[PDO::MYSQL_ATTR_COMPRESS] = true;
        }

        if (!$profilerOn) {
            $this->pdo = new PDO($dsn, (string)$this->config['user'], (string)$this->config['pass'], $options);
            return;
        }

        $logFile   = (string)$this->config['profiler_log'];
        $sample    = max(1, (int)$this->config['profiler_sample']);
        $withStack = (bool)$this->config['profiler_stack'];
        $toTable   = (bool)$this->config['profiler_table'];

        $dir = dirname($logFile);
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }

        $logger   = new QueryLogger(null, $logFile, false, $sample, $withStack);
        $this->pdo = new TimedPDO($dsn, (string)$this->config['user'], (string)$this->config['pass'], $options, $logger);
    }
}
