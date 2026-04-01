<?php
/**
 * Chat Repository — role/vip enrichment + deletes + SEEN + MUTING (UTC-safe + mute snapshots)
 */
declare(strict_types=1);

namespace App\Repositories;

use PDO;

require_once __DIR__ . '/../nickname_helpers.php';

class chat_repository extends base_repository
{
    protected string $table        = 'chat_messages';
    protected string $rooms_table  = 'chat_rooms';
    protected string $seen_table   = 'chat_user_seen';
    protected string $mutes_table  = 'chat_mutes';

    private array $existsCache = [];
    private array $vipCache    = [];
    private array $totalCache  = [];

    public function __construct($app)
    {
        parent::__construct($app);
        $this->bootstrap();
    }

    /* ---------------------- DB helpers (add these) ---------------------- */
    /** Execute and return statement */
    protected function execute(string $sql, array $params = []): \PDOStatement
    {
        $st = $this->get_pdo()->prepare($sql);
        $st->execute($params);
        return $st;
    }
    /** Fetch all rows (assoc) */
    protected function fetch_all(string $sql, array $params = []): array
    {
        return $this->execute($sql, $params)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    /** Fetch single scalar */
    protected function fetch_column(string $sql, array $params = [])
    {
        return $this->execute($sql, $params)->fetchColumn();
    }
    /** Fetch single row (assoc) */
    protected function fetch_row(string $sql, array $params = []): ?array
    {
        $row = $this->execute($sql, $params)->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
    /* ------------------------------------------------------------------- */

    /* ------------------------- helpers (role/vip/etc) ------------------------ */

    private function get_user_role_name(int $user_id): string
    {
        try {
            global $authPdo;
            if ($authPdo && function_exists('auth_get_role_name')) {
                return (string)auth_get_role_name($authPdo, $user_id);
            }
            if (isset($GLOBALS['config']['auth_db'])) {
                $authPdo = $this->get_auth_pdo();
                if ($authPdo && function_exists('auth_get_role_name')) {
                    return (string)auth_get_role_name($authPdo, $user_id);
                }
            }
            $gm = $this->get_user_gmlevel($user_id);
            if     ($gm >= 195) return 'Administrator';
            elseif ($gm >= 191) return 'Trial GM';
            return 'Player';
        } catch (\Throwable) {
            return 'Player';
        }
    }

    private function get_auth_pdo(): ?PDO
    {
        try {
            global $authPdo;
            if ($authPdo instanceof PDO) return $authPdo;
            if (!isset($GLOBALS['config']['auth_db'])) return null;
            $c = $GLOBALS['config']['auth_db'];
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
                    $c['host'] ?? '127.0.0.1', $c['port'] ?? 3306, $c['name'] ?? 'auth', $c['charset'] ?? 'utf8mb4'
                ),
                $c['user'] ?? '', $c['pass'] ?? '',
                [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
            );
            return $pdo;
        } catch (\Throwable) { return null; }
    }

    private function tableExists(string $table): bool
    {
        $k = "t:$table";
        if (isset($this->existsCache[$k])) return $this->existsCache[$k];
        try {
            $st = $this->get_pdo()->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1");
            $st->execute([$table]);
            return $this->existsCache[$k] = (bool)$st->fetchColumn();
        } catch (\Throwable) { return $this->existsCache[$k] = false; }
    }

    private function columnExists(string $table, string $col): bool
    {
        $k = "c:$table:$col";
        if (isset($this->existsCache[$k])) return $this->existsCache[$k];
        try {
            $st = $this->get_pdo()->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
            $st->execute([$table, $col]);
            return $this->existsCache[$k] = (bool)$st->fetchColumn();
        } catch (\Throwable) { return $this->existsCache[$k] = false; }
    }

    private function accountsTable(): string { return 'accounts'; }

    private function userSelectCols(): string
    {
        $a = 'a';
        $gmCol = $this->columnExists($this->accountsTable(), 'gmlevel')
            ? "COALESCE({$a}.gmlevel, 0) AS gmlevel" : "0 AS gmlevel";
        return implode(', ', [
            "COALESCE(NULLIF({$a}.nickname,''), {$a}.username, CONCAT('User ', m.user_id)) AS display_name",
            "{$a}.nickname", "{$a}.username", $gmCol,
        ]);
    }

    /* -------------------------- total spent / vip -------------------------- */

    private function get_user_total_spent(int $user_id): float
    {
        if ($user_id <= 0) return 0.0;
        if (isset($this->totalCache[$user_id])) return $this->totalCache[$user_id];

        $pdo = $this->get_pdo();
        $total = 0.0;

        try {
            $repoFile = __DIR__ . '/donation_repository.php';
            if (is_file($repoFile)) {
                require_once $repoFile;
                if (class_exists('\\DonationRepository')) {
                    $dr = new \DonationRepository($pdo);
                    $t = (float)$dr->getUserTotalSpent($user_id);
                    if ($t > 0) { $this->totalCache[$user_id] = $t; return $t; }
                    $total = max($total, $t);
                }
            }
        } catch (\Throwable $__) {}

        foreach ([[$this->accountsTable(), 'total_spent'], ['users', 'total_spent']] as [$tbl,$col]) {
            if ($this->tableExists($tbl) && $this->columnExists($tbl,$col)) {
                $v = $this->fetch_column("SELECT {$col} FROM {$tbl} WHERE id=? LIMIT 1", [$user_id]);
                if ($v !== false && $v !== null) {
                    $t = (float)$v;
                    if ($t > 0) { $this->totalCache[$user_id] = $t; return $t; }
                    $total = max($total, $t);
                }
            }
        }

        try {
            if ($this->tableExists('donations') && $this->columnExists('donations','amount')) {
                $sum = (float)$this->fetch_column(
                    "SELECT COALESCE(SUM(amount),0)
                       FROM donations
                      WHERE user_id=?
                        AND (status IN ('completed','complete','paid','succeeded') OR status IS NULL)",
                    [$user_id]
                );
                $total = max($total, $sum);
            } elseif ($this->tableExists('payments') && $this->columnExists('payments','amount')) {
                $sum = (float)$this->fetch_column(
                    "SELECT COALESCE(SUM(amount),0)
                       FROM payments
                      WHERE user_id=? AND status IN ('completed','complete','paid','succeeded')",
                    [$user_id]
                );
                $total = max($total, $sum);
            } elseif ($this->tableExists('store_orders') && $this->columnExists('store_orders','total')) {
                $sum = (float)$this->fetch_column(
                    "SELECT COALESCE(SUM(total),0)
                       FROM store_orders
                      WHERE user_id=? AND status IN ('completed','complete','paid','succeeded')",
                    [$user_id]
                );
                $total = max($total, $sum);
            }
        } catch (\Throwable $__) {}

        return $this->totalCache[$user_id] = $total;
    }

    private function get_user_vip_level(int $user_id): int
    {
        if ($user_id <= 0) return 0;
        if (isset($this->vipCache[$user_id])) return $this->vipCache[$user_id];

        $total = $this->get_user_total_spent($user_id);
        $level = (int)min(8, max(0, floor($total / 25)));

        return $this->vipCache[$user_id] = $level;
    }

    private function get_display_name_for_user(int $user_id): string
    { return get_user_nickname($this->get_pdo(), $user_id); }

    private function get_username_for_user(int $user_id): string
    {
        if ($this->tableExists($this->accountsTable()) && $this->columnExists($this->accountsTable(),'username')) {
            $u = $this->fetch_column("SELECT username FROM {$this->accountsTable()} WHERE id=? LIMIT 1", [$user_id]);
            if ($u) return (string)$u;
        }
        if ($this->tableExists('users') && $this->columnExists('users','username')) {
            $u = $this->fetch_column("SELECT username FROM users WHERE id=? LIMIT 1", [$user_id]);
            if ($u) return (string)$u;
        }
        return "User ".$user_id;
    }

    private function get_user_gmlevel(int $userId): int
    {
        $acct = $this->accountsTable();
        foreach (['gmlevel','security_level','gm_level'] as $c) {
            if ($this->tableExists($acct) && $this->columnExists($acct,$c)) {
                $v = $this->fetch_column("SELECT {$c} FROM {$acct} WHERE id=? LIMIT 1", [$userId]);
                if ($v !== false && $v !== null) return (int)$v;
            }
        }
        return 0;
    }

    private function is_user_admin(int $userId): bool
    {
        if ($this->tableExists('users') && $this->columnExists('users','is_admin')) {
            $v = $this->fetch_column("SELECT is_admin FROM users WHERE id=? LIMIT 1", [$userId]);
            if ($v !== false && $v !== null) return (int)$v === 1;
        }
        return $this->get_user_gmlevel($userId) >= 3;
    }

    private function enrich_messages_with_roles(array $messages): array
    {
        foreach ($messages as &$m) {
            $uid = (int)($m['user_id'] ?? 0);
            $role = $this->get_user_role_name($uid);
            $m['user_role'] = $role;
            $m['is_staff']  = ($role !== 'Player');
            if (!isset($m['is_admin']))  $m['is_admin']  = $this->is_user_admin($uid);

            if (!isset($m['vip_level']) || $m['vip_level'] === null) {
                $m['vip_level'] = $this->get_user_vip_level($uid);
            }

            if (empty($m['nickname']) && $uid > 0) {
                $nick = ensure_user_nickname($this->get_pdo(), $uid);
                $m['nickname']     = $nick;
                $m['display_name'] = $nick ?: ($m['display_name'] ?? $this->get_username_for_user($uid));
            }
        }
        return $messages;
    }

    /* ------------------------------ seen/rooms ------------------------------ */

    public function mark_messages_seen(int $user_id, string $room='general'): void
    {
        $room = $this->sanitize_alphanumeric($room) ?: 'general';
        $sql = "INSERT INTO {$this->seen_table} (user_id, room, last_seen_at)
                VALUES (?, ?, UTC_TIMESTAMP())
                ON DUPLICATE KEY UPDATE last_seen_at=UTC_TIMESTAMP()";
        $this->execute($sql, [$user_id, $room]);
    }

    public function get_unseen_count(int $user_id, string $room='general'): int
    {
        $room = $this->sanitize_alphanumeric($room) ?: 'general';
        $last = $this->fetch_column("SELECT last_seen_at FROM {$this->seen_table} WHERE user_id=? AND room=? LIMIT 1", [$user_id,$room]);
        if (!$last) {
            return (int)$this->fetch_column(
                "SELECT COUNT(*) FROM {$this->table}
                 WHERE room=? AND is_deleted=0
                   AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)
                   AND user_id != ?",
                [$room,$user_id]
            );
        }
        return (int)$this->fetch_column(
            "SELECT COUNT(*) FROM {$this->table}
             WHERE room=? AND is_deleted=0 AND created_at > ? AND user_id != ?",
            [$room,$last,$user_id]
        );
    }

    public function get_active_rooms(): array
    {
        $rows = $this->fetch_all(
            "SELECT room, COUNT(*) AS message_count, MAX(created_at) AS last_activity
             FROM {$this->table}
             WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR) AND is_deleted=0
             GROUP BY room
             ORDER BY last_activity DESC
             LIMIT 10"
        );
        $hasGen = false; foreach ($rows as $r) if (($r['room'] ?? '') === 'general') { $hasGen = true; break; }
        if (!$hasGen) array_unshift($rows, ['room'=>'general','message_count'=>0,'last_activity'=>null]);
        return $rows;
    }

    public function get_online_count(string $room='general'): int
    {
        try { $online = new online_tracking_repository($this->app); return $online->get_online_count($room); }
        catch (\Throwable) {
            $room = $this->sanitize_alphanumeric($room) ?: 'general';
            return (int)$this->fetch_column(
                "SELECT COUNT(DISTINCT user_id) FROM {$this->table}
                 WHERE room=? AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)",
                [$room]
            );
        }
    }

    /* ------------------------------- queries -------------------------------- */

    public function get_recent_messages(string $room='general', int $limit=50): array
    {
        $room = $this->sanitize_alphanumeric($room) ?: 'general';
        $limit = max(1, min(100, $limit));

        $join = $this->tableExists($this->accountsTable());
        if ($join) {
            $cols = $this->userSelectCols();
            $sql = "SELECT m.*, {$cols}
                    FROM {$this->table} m
                    LEFT JOIN {$this->accountsTable()} a ON m.user_id = a.id
                    WHERE m.room=? AND m.is_deleted=0
                    ORDER BY m.created_at DESC
                    LIMIT {$limit}";
            $rows = $this->fetch_all($sql, [$room]);
            $rows = array_reverse($rows);
        } else {
            $rows = $this->fetch_all(
                "SELECT * FROM {$this->table}
                 WHERE room=? AND is_deleted=0
                 ORDER BY created_at DESC
                 LIMIT {$limit}",
                [$room]
            );
            $rows = array_reverse($rows);
            foreach ($rows as &$r) {
                $uid = (int)($r['user_id'] ?? 0);
                $r['username']     = $this->get_username_for_user($uid);
                $r['display_name'] = $this->get_display_name_for_user($uid);
                $info = get_user_nickname_info($this->get_pdo(), $uid);
                $r['nickname'] = $info['nickname'] ?? null;
                $r['gmlevel']  = 0;
            }
        }

        foreach ($rows as &$r) {
            $uid = (int)($r['user_id'] ?? 0);
            $r['is_admin']  = $this->is_user_admin($uid);
            if (!isset($r['vip_level']) || $r['vip_level'] === null) {
                $r['vip_level'] = $this->get_user_vip_level($uid);
            }
            if (empty($r['nickname']) && $uid > 0) {
                $nick = ensure_user_nickname($this->get_pdo(), $uid);
                $r['nickname']     = $nick;
                $r['display_name'] = $nick ?: ($r['display_name'] ?? $this->get_username_for_user($uid));
            }
        }
        return $this->enrich_messages_with_roles($rows);
    }

    public function get_messages_since(string $room='general', int $timestamp=0): array
    {
        $room = $this->sanitize_alphanumeric($room) ?: 'general';
        $timestamp = max(0, $timestamp);

        $join = $this->tableExists($this->accountsTable());
        if ($join) {
            $cols = $this->userSelectCols();
            $sql = "SELECT m.*, {$cols}
                    FROM {$this->table} m
                    LEFT JOIN {$this->accountsTable()} a ON m.user_id = a.id
                    WHERE m.room=? AND m.is_deleted=0
                      AND UNIX_TIMESTAMP(m.created_at) > ?
                    ORDER BY m.created_at ASC";
            $rows = $this->fetch_all($sql, [$room, $timestamp]);
        } else {
            $rows = $this->fetch_all(
                "SELECT * FROM {$this->table}
                 WHERE room=? AND is_deleted=0 AND UNIX_TIMESTAMP(created_at) > ?
                 ORDER BY created_at ASC",
                [$room, $timestamp]
            );
            foreach ($rows as &$r) {
                $uid = (int)($r['user_id'] ?? 0);
                $r['username']     = $this->get_username_for_user($uid);
                $r['display_name'] = $this->get_display_name_for_user($uid);
                $info = get_user_nickname_info($this->get_pdo(), $uid);
                $r['nickname'] = $info['nickname'] ?? null;
                $r['gmlevel']  = 0;
            }
        }

        foreach ($rows as &$r) {
            $uid = (int)($r['user_id'] ?? 0);
            $r['is_admin']  = $this->is_user_admin($uid);
            if (!isset($r['vip_level']) || $r['vip_level'] === null) {
                $r['vip_level'] = $this->get_user_vip_level($uid);
            }
            if (empty($r['nickname']) && $uid > 0) {
                $nick = ensure_user_nickname($this->get_pdo(), $uid);
                $r['nickname']     = $nick;
                $r['display_name'] = $nick ?: ($r['display_name'] ?? $this->get_username_for_user($uid));
            }
        }
        return $this->enrich_messages_with_roles($rows);
    }

    public function send_message(int $user_id, string $message, string $room='general'): int
    {
        $room = $this->sanitize_alphanumeric($room) ?: 'general';
        $message = trim($message);
        if ($message === '' || mb_strlen($message) > 500) {
            throw new \InvalidArgumentException('Invalid message length');
        }
        $sql = "INSERT INTO {$this->table} (user_id, room, message, created_at)
                VALUES (?, ?, ?, UTC_TIMESTAMP())";
        $this->execute($sql, [$user_id, $room, $message]);
        return (int)$this->get_pdo()->lastInsertId();
    }

    public function delete_message(int $message_id, int $user_id, bool $is_admin=false): bool
    {
        $meta = $this->get_message_meta($message_id);
        if (!$meta) return false;
        if ((int)$meta['is_deleted'] === 1) return true;
        $ownerId = (int)$meta['user_id'];
        if (!$is_admin && $ownerId !== $user_id) return false;
        return $this->soft_delete_by_id($message_id, $user_id, null);
    }

    public function get_message_meta(int $id): ?array
    {
        return $this->fetch_row(
            "SELECT id, user_id, room, created_at, is_deleted FROM {$this->table} WHERE id=? LIMIT 1",
            [$id]
        );
    }

    public function soft_delete_by_id(int $id, int $byUserId, ?string $reason=null): bool
    {
        $this->ensure_delete_audit_columns();
        $params = [':id'=>$id];
        $set = ["is_deleted=1"];
        if ($this->columnExists($this->table,'deleted_at'))  $set[]="deleted_at=UTC_TIMESTAMP()";
        if ($this->columnExists($this->table,'deleted_by')) { $set[]="deleted_by=:by"; $params[':by']=$byUserId; }
        if ($this->columnExists($this->table,'deleted_reason') && $reason!==null) { $set[]="deleted_reason=:rsn"; $params[':rsn']=mb_substr($reason,0,64); }
        $sql = "UPDATE {$this->table} SET ".implode(', ',$set)." WHERE id=:id AND is_deleted=0";
        $st = $this->execute($sql, $params);
        return $st->rowCount() > 0;
    }

    public function hard_delete_by_id(int $id): bool
    { $st = $this->execute("DELETE FROM {$this->table} WHERE id=?", [$id]); return $st->rowCount()>0; }

    /*** safe edit entrypoint used by /api/chat-edit.php ***/
    public function edit_own_message(int $id, int $userId, string $room, string $text): array
    {
        $room = $this->sanitize_alphanumeric($room) ?: 'general';
        $text = trim($text);
        if ($id <= 0) throw new \InvalidArgumentException('Invalid message id');
        if ($text === '' || mb_strlen($text) > 500) {
            throw new \InvalidArgumentException('Invalid message length');
        }

        $meta = $this->get_message_meta($id);
        if (!$meta || (int)$meta['is_deleted'] === 1) {
            throw new \RuntimeException('Message not found');
        }
        if ((int)$meta['user_id'] !== $userId) {
            throw new \RuntimeException('Not your message');
        }
        if (strcasecmp((string)($meta['room'] ?? ''), $room) !== 0) {
            throw new \RuntimeException('Wrong room');
        }

        $this->ensure_edit_columns();

        $set = ['message = :msg'];
        $params = [':msg' => $text, ':id' => $id];

        if ($this->columnExists($this->table, 'edited_at')) {
            $set[] = 'edited_at = UTC_TIMESTAMP()';
        }
        if ($this->columnExists($this->table, 'edited_by')) {
            $set[] = 'edited_by = :by';
            $params[':by'] = $userId;
        }
        if ($this->columnExists($this->table, 'edit_count')) {
            $set[] = 'edit_count = COALESCE(edit_count,0) + 1';
        }

        $sql = "UPDATE {$this->table} SET ".implode(', ', $set)." WHERE id = :id AND is_deleted = 0";
        $st  = $this->execute($sql, $params);
        if ($st->rowCount() < 1) {
            throw new \RuntimeException('Nothing updated');
        }

        return [
            'id'         => $id,
            'text'       => $text,
            'edited'     => true,
            'updated_ts' => time(),
        ];
    }

    protected function get_pdo(): PDO
    {
        if (isset($this->pdo)) return $this->pdo;
        if (isset($this->app) && method_exists($this->app,'getPdo')) {
            $pdo = $this->app->getPdo(); if ($pdo instanceof PDO) return $pdo;
        }
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];
        throw new \RuntimeException('PDO connection not available');
    }

    public function getPdo(): PDO { return $this->get_pdo(); }

    private function ensure_delete_audit_columns(): void
    {
        try {
            $alter = [];
            if (!$this->columnExists($this->table,'deleted_at'))   $alter[]="ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER is_deleted";
            if (!$this->columnExists($this->table,'deleted_by')) { $alter[]="ADD COLUMN deleted_by INT NULL DEFAULT NULL AFTER deleted_at"; $alter[]="ADD INDEX idx_deleted_by (deleted_by)"; }
            if (!$this->columnExists($this->table,'deleted_reason')) $alter[]="ADD COLUMN deleted_reason VARCHAR(64) NULL DEFAULT NULL AFTER deleted_by";
            if ($alter) $this->get_pdo()->exec("ALTER TABLE {$this->table} ".implode(', ',$alter));
        } catch (\Throwable $e) { error_log("ensure_delete_audit_columns: ".$e->getMessage()); }
    }

    private function ensure_edit_columns(): void
    {
        try {
            $alter = [];
            if (!$this->columnExists($this->table, 'edited_at')) {
                $alter[] = "ADD COLUMN edited_at TIMESTAMP NULL DEFAULT NULL AFTER created_at";
            }
            if (!$this->columnExists($this->table, 'edited_by')) {
                $alter[] = "ADD COLUMN edited_by INT NULL DEFAULT NULL AFTER edited_at";
            }
            if (!$this->columnExists($this->table, 'edit_count')) {
                $alter[] = "ADD COLUMN edit_count INT NULL DEFAULT 0 AFTER edited_by";
            }
            if ($alter) {
                $this->get_pdo()->exec("ALTER TABLE {$this->table} " . implode(', ', $alter));
            }
        } catch (\Throwable $e) {
            error_log('ensure_edit_columns: '.$e->getMessage());
        }
    }

    /* ------------------------------- muting -------------------------------- */

    public function is_user_muted(int $userId, ?string $room=null): ?array
    {
        $room = $room ? preg_replace('/[^a-z0-9\-_]/i','',$room) : null;
        if (!$this->tableExists($this->mutes_table)) return null;

        try {
            $p = $this->get_pdo()->prepare("DELETE FROM {$this->mutes_table}
                                      WHERE user_id=:uid
                                        AND expires_at IS NOT NULL
                                        AND expires_at <= UTC_TIMESTAMP()");
            $p->execute([':uid' => $userId]);
        } catch (\Throwable) {}

        $sql = "SELECT id, user_id, muted_by, room, reason, expires_at, created_at,
                       muted_username, muted_nickname, muted_by_username
                FROM {$this->mutes_table}
                WHERE user_id = :uid
                  AND (:room IS NULL OR room IS NULL OR room = :room)
                  AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())
                ORDER BY COALESCE(expires_at, '9999-12-31 23:59:59') DESC
                LIMIT 1";
        $st = $this->get_pdo()->prepare($sql);
        $st->execute([':uid'=>$userId, ':room'=>$room]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        return $row ?: null;
    }

    public function mute_user(int $targetId, int $byId, ?string $room, ?int $seconds, ?string $reason = null): bool
    {
        $room = $room ? preg_replace('/[^a-z0-9\-_]/i', '', $room) : null;
        if (!$this->tableExists($this->mutes_table)) $this->ensure_mutes_table();

        if ($room === null) {
            $sqlDel = "DELETE FROM {$this->mutes_table}
                       WHERE user_id = :uid AND room IS NULL
                         AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())";
            $del = $this->get_pdo()->prepare($sqlDel);
            $del->execute([':uid' => $targetId]);
        } else {
            $sqlDel = "DELETE FROM {$this->mutes_table}
                       WHERE user_id = :uid AND room = :room
                         AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())";
            $del = $this->get_pdo()->prepare($sqlDel);
            $del->execute([':uid' => $targetId, ':room' => $room]);
        }

        $muted_username     = $this->get_username_for_user($targetId);
        $muted_nickname     = $this->get_display_name_for_user($targetId);
        $muted_by_username  = $this->get_username_for_user($byId);

        $hasMutedUsername    = $this->columnExists($this->mutes_table, 'muted_username');
        $hasMutedNickname    = $this->columnExists($this->mutes_table, 'muted_nickname');
        $hasMutedByUsername  = $this->columnExists($this->mutes_table, 'muted_by_username');

        $cols   = ['user_id', 'muted_by', 'room', 'reason', 'expires_at', 'created_at'];
        $params = [':uid' => $targetId, ':by' => $byId, ':room' => $room, ':reason' => $reason];

        if ($seconds === null) {
            $vals = [':uid', ':by', ':room', ':reason', 'NULL', 'UTC_TIMESTAMP()'];
        } else {
            $vals = [':uid', ':by', ':room', ':reason', 'DATE_ADD(UTC_TIMESTAMP(), INTERVAL :seconds SECOND)', 'UTC_TIMESTAMP()'];
            $params[':seconds'] = $seconds;
        }

        if ($hasMutedUsername)   { $cols[] = 'muted_username';    $vals[] = ':m_user';  $params[':m_user']  = $muted_username; }
        if ($hasMutedNickname)   { $cols[] = 'muted_nickname';    $vals[] = ':m_nick';  $params[':m_nick']  = $muted_nickname; }
        if ($hasMutedByUsername) { $cols[] = 'muted_by_username'; $vals[] = ':by_user'; $params[':by_user'] = $muted_by_username; }

        $sqlIns = "INSERT INTO {$this->mutes_table} (".implode(',', $cols).") VALUES (".implode(',', $vals).")";
        $ins = $this->get_pdo()->prepare($sqlIns);
        return $ins->execute($params);
    }

    public function unmute_user(int $targetId, ?string $room=null): bool
    {
        $room = $room ? preg_replace('/[^a-z0-9\-_]/i','',$room) : null;
        if (!$this->tableExists($this->mutes_table)) return false;
        $sql = "DELETE FROM {$this->mutes_table}
                WHERE user_id = :uid
                  AND (:room IS NULL OR room IS NULL OR room = :room)
                  AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())";
        $st = $this->get_pdo()->prepare($sql);
        return $st->execute([':uid'=>$targetId, ':room'=>$room]);
    }

    /* ------------------------------ bootstrap ------------------------------- */

    private function ensure_mutes_table(): void
    {
        try {
            $ddl = "
            CREATE TABLE IF NOT EXISTS {$this->mutes_table} (
              id INT AUTO_INCREMENT PRIMARY KEY,
              user_id INT NOT NULL,
              muted_by INT NOT NULL,
              room VARCHAR(64) NULL,
              reason VARCHAR(255) NULL,
              expires_at DATETIME NULL,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              muted_username VARCHAR(100) NULL,
              muted_nickname VARCHAR(120) NULL,
              muted_by_username VARCHAR(100) NULL,
              INDEX idx_user_room (user_id, room),
              INDEX idx_expires (expires_at),
              INDEX idx_muted_username (muted_username),
              INDEX idx_muted_nickname (muted_nickname)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            $this->get_pdo()->exec($ddl);
            $this->existsCache["t:{$this->mutes_table}"] = true;

            $alter = [];
            if (!$this->columnExists($this->mutes_table, 'muted_username')) {
                $alter[] = "ADD COLUMN muted_username VARCHAR(100) NULL AFTER created_at";
                $alter[] = "ADD INDEX idx_muted_username (muted_username)";
            }
            if (!$this->columnExists($this->mutes_table, 'muted_nickname')) {
                $alter[] = "ADD COLUMN muted_nickname VARCHAR(120) NULL AFTER muted_username";
                $alter[] = "ADD INDEX idx_muted_nickname (muted_nickname)";
            }
            if (!$this->columnExists($this->mutes_table, 'muted_by_username')) {
                $alter[] = "ADD COLUMN muted_by_username VARCHAR(100) NULL AFTER muted_nickname";
            }
            if ($alter) {
                $this->get_pdo()->exec("ALTER TABLE {$this->mutes_table} " . implode(', ', $alter));
            }
        } catch (\Throwable $e) {
            error_log("ensure_mutes_table: ".$e->getMessage());
        }
    }

    private function bootstrap(): void
    {
        try {
            $this->create_table_if_not_exists("
              CREATE TABLE IF NOT EXISTS {$this->table} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                room VARCHAR(64) NOT NULL DEFAULT 'general',
                message TEXT NOT NULL,
                is_deleted TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_room_time (room, created_at),
                INDEX idx_user (user_id),
                INDEX idx_deleted (is_deleted)
              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $this->create_table_if_not_exists("
              CREATE TABLE IF NOT EXISTS {$this->rooms_table} (
                name VARCHAR(64) NOT NULL PRIMARY KEY,
                display_name VARCHAR(100) NOT NULL,
                description TEXT,
                is_public TINYINT(1) NOT NULL DEFAULT 1,
                max_users INT DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $this->create_table_if_not_exists("
              CREATE TABLE IF NOT EXISTS {$this->seen_table} (
                user_id INT NOT NULL,
                room VARCHAR(64) NOT NULL DEFAULT 'general',
                last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, room),
                INDEX idx_user_room (user_id, room)
              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            try {
                $this->execute("INSERT IGNORE INTO {$this->rooms_table} (name, display_name, description)
                                VALUES ('general','General Chat','Main chat room for all players')");
            } catch (\Throwable) {}

            try { $this->ensure_delete_audit_columns(); } catch (\Throwable) {}

            $this->ensure_mutes_table();
        } catch (\Throwable $e) {
            error_log("Chat repository bootstrap failed: ".$e->getMessage());
        }
    }
}
