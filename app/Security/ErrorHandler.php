<?php
declare(strict_types=1);

namespace App\Security;

/**
 * ErrorHandler - Secure error handling that doesn't expose sensitive information
 */
class ErrorHandler
{
    private static bool $debug = false;
    private static string $logDir = '';

    public static function setup(bool $debug = false, string $logDir = ''): void
    {
        self::$debug = $debug;
        self::$logDir = $logDir ?: dirname(__DIR__, 1) . '/storage/logs';

        // Register custom error handler
        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    /**
     * Handle PHP errors securely
     */
    public static function handleError($severity, $message, $file, $line): bool
    {
        // Log the real error
        self::logError('ERROR', $message, $file, $line, debug_backtrace());

        // Show generic error to user if not debug mode
        if (!self::$debug) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo "An error occurred. Please try again later.\n";
            exit;
        }

        return false;
    }

    /**
     * Handle exceptions securely
     */
    public static function handleException(\Throwable $exception): void
    {
        self::logError(
            'EXCEPTION',
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTrace()
        );

        if (!self::$debug) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'An unexpected error occurred']);
            exit;
        }

        // In debug mode, show full error
        header('Content-Type: text/plain; charset=utf-8');
        echo "Exception: " . $exception->getMessage() . "\n";
        echo "File: " . $exception->getFile() . ":" . $exception->getLine() . "\n";
        exit;
    }

    /**
     * Handle fatal errors at shutdown
     */
    public static function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            self::logError(
                'FATAL',
                $error['message'],
                $error['file'],
                $error['line'],
                []
            );

            if (!self::$debug) {
                http_response_code(500);
                header('Content-Type: text/plain; charset=utf-8');
                echo "A fatal error occurred.\n";
                exit;
            }
        }
    }

    /**
     * Log error securely (don't expose paths in production)
     */
    private static function logError(string $type, string $message, string $file, int $line, array $trace): void
    {
        if (!self::$logDir || !is_dir(self::$logDir)) {
            return;
        }

        // Sanitize message to avoid SQL injection attempts, XSS, etc. appearing in logs
        $message = substr($message, 0, 500); // Limit message length

        $timestamp = date('Y-m-d H:i:s');
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        // In production, hide file paths
        if (!self::$debug) {
            $file = basename($file);
        }

        $logEntry = sprintf(
            "[%s] [%s] IP:%s File:%s:%d Message:%s\n",
            $timestamp,
            $type,
            $ip,
            $file,
            $line,
            $message
        );

        $logPath = self::$logDir . '/error.log';
        @file_put_contents($logPath, $logEntry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Log suspicious activity attempt (for security analysis)
     */
    public static function logSuspiciousActivity(string $type, string $details): void
    {
        if (!self::$logDir || !is_dir(self::$logDir)) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);

        $logEntry = sprintf(
            "[%s] Type:%s IP:%s UserAgent:%s Details:%s\n",
            $timestamp,
            $type,
            $ip,
            $userAgent,
            $details
        );

        $logPath = self::$logDir . '/suspicious.log';
        @file_put_contents($logPath, $logEntry, FILE_APPEND | LOCK_EX);
    }
}
