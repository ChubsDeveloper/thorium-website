<?php
/**
 * Security Logger
 * 
 * Comprehensive security event logging system for tracking authentication attempts,
 * suspicious activities, and security-related events. Captures IP addresses,
 * user agents, and detailed event information for security analysis.
 */
declare(strict_types=1);

namespace App\Security;

class SecurityLogger
{
    private \PDO $pdo;
    private string $logDir;

    /**
     * Initialize security logger with database connection
     *
     * @param \PDO $pdo Database connection
     */
    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->logDir = dirname(__DIR__, 1) . '/storage/logs';
        $this->ensureLogDir();
    }
    
    /**
     * Log security event to database
     * 
     * @param string $eventType Type of security event (login_attempt, failed_auth, etc.)
     * @param int|null $userId User ID if applicable
     * @param string $details Additional event details
     */
    public function logSecurityEvent(string $eventType, ?int $userId, string $details = ''): void
    {
        $ip = $this->getClientIp();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        $sql = "INSERT INTO security_log (event_type, user_id, ip_address, user_agent, details) 
                VALUES (?, ?, ?, ?, ?)";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$eventType, $userId, $ip, $userAgent, $details]);
    }
    
    /**
     * Log failed login attempt to file (for brute force detection)
     */
    public function logFailedLogin(string $username): void
    {
        $this->logSecurityEvent('failed_login', null, "Username: {$username}");

        $timestamp = date('Y-m-d H:i:s');
        $ip = $this->getClientIp();
        $logEntry = "[{$timestamp}] IP:{$ip} Username:{$username}\n";
        $logPath = $this->logDir . '/failed_logins.log';
        @file_put_contents($logPath, $logEntry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Log successful admin/sensitive action (audit trail)
     */
    public function logAdminAction(string $action, int $userId, string $details = ''): void
    {
        $this->logSecurityEvent('admin_action', $userId, "{$action}: {$details}");

        $timestamp = date('Y-m-d H:i:s');
        $ip = $this->getClientIp();
        $logEntry = "[{$timestamp}] Admin:{$userId} IP:{$ip} Action:{$action} Details:{$details}\n";
        $logPath = $this->logDir . '/audit.log';
        @file_put_contents($logPath, $logEntry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Check if IP/username is being brute forced
     */
    public function checkBruteForce(string $identifier, int $maxAttempts = 3, int $timeWindowSeconds = 900): bool
    {
        $logPath = $this->logDir . '/failed_logins.log';
        if (!file_exists($logPath)) {
            return false;
        }

        $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $now = time();
        $recentAttempts = 0;

        foreach (array_reverse($lines) as $line) {
            if (strpos($line, $identifier) === false) {
                continue;
            }

            // Parse timestamp from log entry
            if (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $matches)) {
                $attemptTime = strtotime($matches[1]);
                if ($now - $attemptTime > $timeWindowSeconds) {
                    break;
                }
                $recentAttempts++;
            }
        }

        return $recentAttempts >= $maxAttempts;
    }

    /**
     * Get client IP address with comprehensive proxy header support
     *
     * @return string Client IP address
     */
    private function getClientIp(): string
    {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                return trim($ips[0]);
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Ensure log directory exists with proper permissions
     */
    private function ensureLogDir(): void
    {
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0750, true);
        }
    }
}
