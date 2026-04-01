<?php
/**
 * Rate Limiter
 * 
 * IP-based rate limiting system to prevent abuse and brute force attacks.
 * Tracks attempts per endpoint with configurable limits and blocking periods.
 * Includes automatic cleanup of old entries and proper IP detection.
 */
declare(strict_types=1);

namespace App\Security;

class RateLimiter
{
    private \PDO $pdo;
    
    /**
     * Initialize rate limiter with database connection
     * 
     * @param \PDO $pdo Database connection
     */
    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }
    
    /**
     * Check if current IP is rate limited for given endpoint
     * 
     * @param string $endpoint Endpoint identifier
     * @param int $maxAttempts Maximum attempts allowed (default: 5)
     * @param int $windowMinutes Blocking window in minutes (default: 15)
     * @return bool True if rate limited
     */
    public function isRateLimited(string $endpoint, int $maxAttempts = 5, int $windowMinutes = 15): bool
    {
        $ip = $this->getClientIp();
        
        $this->cleanupOldEntries();
        
        // Check current attempts
        $sql = "SELECT attempts, blocked_until FROM rate_limits 
                WHERE ip_address = ? AND endpoint = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$ip, $endpoint]);
        $row = $stmt->fetch();
        
        if (!$row) {
            // First attempt
            $this->recordAttempt($ip, $endpoint);
            return false;
        }
        
        // Check if still blocked
        if ($row['blocked_until'] && strtotime($row['blocked_until']) > time()) {
            return true;
        }
        
        // Check if too many attempts
        if ($row['attempts'] >= $maxAttempts) {
            $this->blockIp($ip, $endpoint, $windowMinutes);
            return true;
        }
        
        // Record this attempt
        $this->recordAttempt($ip, $endpoint, $row['attempts'] + 1);
        return false;
    }
    
    /**
     * Record rate limit attempt for IP and endpoint
     * 
     * @param string $ip Client IP address
     * @param string $endpoint Endpoint identifier
     * @param int $attempts Current attempt count
     */
    private function recordAttempt(string $ip, string $endpoint, int $attempts = 1): void
    {
        $sql = "INSERT INTO rate_limits (ip_address, endpoint, attempts, last_attempt) 
                VALUES (?, ?, ?, NOW()) 
                ON DUPLICATE KEY UPDATE 
                attempts = ?, last_attempt = NOW()";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$ip, $endpoint, $attempts, $attempts]);
    }
    
    /**
     * Block IP address for specified duration
     * 
     * @param string $ip Client IP address
     * @param string $endpoint Endpoint identifier
     * @param int $minutes Block duration in minutes
     */
    private function blockIp(string $ip, string $endpoint, int $minutes): void
    {
        $sql = "UPDATE rate_limits 
                SET blocked_until = DATE_ADD(NOW(), INTERVAL ? MINUTE) 
                WHERE ip_address = ? AND endpoint = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$minutes, $ip, $endpoint]);
    }
    
    /**
     * Clean up old rate limit entries (older than 24 hours)
     */
    private function cleanupOldEntries(): void
    {
        $sql = "DELETE FROM rate_limits 
                WHERE last_attempt < DATE_SUB(NOW(), INTERVAL 24 HOUR)";
        $this->pdo->exec($sql);
    }
    
    /**
     * Get client IP address with proxy header support
     * 
     * @return string Client IP address
     */
    private function getClientIp(): string
    {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                return trim(explode(',', $_SERVER[$header])[0]);
            }
        }
        
        return '0.0.0.0';
    }
}
