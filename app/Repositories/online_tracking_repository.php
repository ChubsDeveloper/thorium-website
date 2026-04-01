<?php
/**
 * app/Repositories/online_tracking_repository.php
 * Data access repository - manages online user tracking
 */
declare(strict_types=1);

namespace App\Repositories;

class online_tracking_repository extends base_repository
{
    protected string $table = 'chat_online_users';

    /** Initialize the class instance. */
    public function __construct($app)
    {
        parent::__construct($app);
        $this->bootstrap();
    }

    /** Update user's online status (heartbeat) */
    public function update_user_heartbeat(int $user_id, string $room = 'general'): void
    {
        $room = $this->sanitize_alphanumeric($room);
        if (empty($room)) $room = 'general';

        $sql = "INSERT INTO {$this->table} (user_id, room, last_seen, session_id) 
                VALUES (?, ?, NOW(), ?) 
                ON DUPLICATE KEY UPDATE 
                last_seen = NOW(), 
                room = VALUES(room),
                session_id = VALUES(session_id)";
        
        $session_id = session_id() ?: 'unknown';
        $this->execute($sql, [$user_id, $room, $session_id]);
    }

    /** Get count of online users in a room */
    public function get_online_count(string $room = 'general'): int
    {
        $room = $this->sanitize_alphanumeric($room);
        if (empty($room)) $room = 'general';

        // Consider users online if they had activity in last 2 minutes
        $sql = "SELECT COUNT(DISTINCT user_id) 
                FROM {$this->table} 
                WHERE room = ? 
                AND last_seen >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)";
        
        return (int)$this->fetch_column($sql, [$room]);
    }

    /** Get list of online users with details */
    public function get_online_users(string $room = 'general'): array
    {
        $room = $this->sanitize_alphanumeric($room);
        if (empty($room)) $room = 'general';

        $sql = "SELECT o.user_id, o.last_seen,
                COALESCE(a.username, CONCAT('User ', o.user_id)) as username,
                COALESCE(a.gmlevel, 0) as gmlevel
                FROM {$this->table} o
                LEFT JOIN thorium_website.accounts a ON o.user_id = a.id
                WHERE o.room = ? 
                AND o.last_seen >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
                ORDER BY o.last_seen DESC";
        
        try {
            return $this->fetch_all($sql, [$room]);
        } catch (\Throwable $e) {
            // Fallback without database prefix
            $sql_fallback = "SELECT o.user_id, o.last_seen,
                            COALESCE(a.username, CONCAT('User ', o.user_id)) as username,
                            COALESCE(a.gmlevel, 0) as gmlevel
                            FROM {$this->table} o
                            LEFT JOIN accounts a ON o.user_id = a.id
                            WHERE o.room = ? 
                            AND o.last_seen >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
                            ORDER BY o.last_seen DESC";
            
            return $this->fetch_all($sql_fallback, [$room]);
        }
    }

    /** Clean up old entries (called periodically) */
    public function cleanup_old_entries(): void
    {
        // Remove entries older than 10 minutes
        $sql = "DELETE FROM {$this->table} 
                WHERE last_seen < DATE_SUB(NOW(), INTERVAL 10 MINUTE)";
        $this->execute($sql);
    }

    /** Remove user from online tracking (when they leave) */
    public function remove_user(int $user_id, ?string $session_id = null): void
    {
        if ($session_id) {
            $sql = "DELETE FROM {$this->table} WHERE user_id = ? AND session_id = ?";
            $this->execute($sql, [$user_id, $session_id]);
        } else {
            $sql = "DELETE FROM {$this->table} WHERE user_id = ?";
            $this->execute($sql, [$user_id]);
        }
    }

    /** Bootstrap the online tracking table */
    private function bootstrap(): void
    {
        $schema = "
            CREATE TABLE IF NOT EXISTS {$this->table} (
                user_id INT NOT NULL,
                room VARCHAR(64) NOT NULL DEFAULT 'general',
                last_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                session_id VARCHAR(128) NOT NULL DEFAULT '',
                PRIMARY KEY (user_id, room),
                INDEX idx_room_lastseen (room, last_seen),
                INDEX idx_session (session_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        
        $this->create_table_if_not_exists($schema);
    }
}
