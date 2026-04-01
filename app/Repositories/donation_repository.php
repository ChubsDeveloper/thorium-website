<?php
/**
 * DonationRepository
 * - Schema-aware (uses INFORMATION_SCHEMA to avoid query errors)
 * - No parameterized LIMIT (MariaDB compat) — limits are clamped & inlined
 * - Smart points integration (auth/site compatible)
 * - PRIORITY: donation_logs table for real PayPal data
 */

declare(strict_types=1);

class DonationRepository
{
    private PDO $db;
    private string $dbName = '';

    public function __construct(PDO $db)
    {
        $this->db = $db;
        // Try to detect current DB for INFORMATION_SCHEMA queries
        try {
            $this->dbName = (string)$this->db->query('SELECT DATABASE()')->fetchColumn();
        } catch (\Throwable $e) {
            $this->dbName = '';
        }

        // Ensure points_repo.php is loaded for smart functions
        if (!function_exists('points_get_main')) {
            $points_repo_path = __DIR__ . '/../points_repo.php';
            if (is_file($points_repo_path)) {
                require_once $points_repo_path;
            }
        }
    }

    /* ---------- Small utilities ---------- */

    private function clampLimit(int $limit, int $max = 50): int
    {
        $n = max(1, (int)$limit);
        return min($n, $max);
    }

    private function tableExists(string $table): bool
    {
        if ($this->dbName === '') return false;
        try {
            $sql = "SELECT 1
                    FROM INFORMATION_SCHEMA.TABLES
                    WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl
                    LIMIT 1";
            $st = $this->db->prepare($sql);
            $st->execute([':db' => $this->dbName, ':tbl' => $table]);
            return (bool)$st->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function columnsExist(string $table, array $cols): bool
    {
        if ($this->dbName === '') return false;
        try {
            $place = implode(',', array_fill(0, count($cols), '?'));
            $sql = "SELECT COUNT(*)
                    FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME IN ($place)";
            $params = array_merge([$this->dbName, $table], $cols);
            $st = $this->db->prepare($sql);
            $st->execute($params);
            return ((int)$st->fetchColumn()) === count($cols);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function hasColumn(string $table, string $col): bool
    {
        return $this->columnsExist($table, [$col]);
    }

    /* ---------- Packages ---------- */

    public function getDonationPackages(): array
    {
        try {
            $st = $this->db->prepare("
                SELECT id, amount, title, points, bonus_points, recommended, sku, icon, frame
                FROM donation_packages
                WHERE active = 1
                ORDER BY sort_order ASC, amount ASC
            ");
            $st->execute();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            $out = [];
            foreach ($rows as $r) {
                $total = (int)$r['points'] + (int)$r['bonus_points'];
                $out[$r['sku']] = [
                    'id'            => (int)$r['id'],
                    'name'          => (string)$r['title'],
                    'amount'        => (float)$r['amount'],
                    'points'        => (int)$r['points'],
                    'bonus_points'  => (int)$r['bonus_points'],
                    'total_points'  => $total,
                    'recommended'   => (bool)$r['recommended'],
                    'sku'           => (string)$r['sku'],
                    'icon'          => (string)$r['icon'],
                    'frame'         => (string)$r['frame'],
                ];
            }
            return $out ?: [];
        } catch (\Throwable $e) {
            // Minimal safe fallback
            return [
                'sample1' => [
                    'id' => 1, 'name' => 'Sample Package', 'amount' => 10.00,
                    'points' => 100, 'bonus_points' => 0, 'total_points' => 100,
                    'recommended' => false, 'sku' => 'sample1', 'icon' => '', 'frame' => ''
                ],
            ];
        }
    }

    /* ---------- Balances (smart first) ---------- */

    public function getUserDonationPoints(int $user_id): int
    {
        if (function_exists('points_get_main')) {
            $b = points_get_main($user_id);
            return (int)$b['donation'];
        }
        try {
            if ($this->tableExists('accounts') && $this->hasColumn('accounts', 'dp')) {
                $st = $this->db->prepare("SELECT COALESCE(dp,0) FROM accounts WHERE id = ?");
                $st->execute([$user_id]);
                return (int)$st->fetchColumn();
            }
        } catch (\Throwable $e) {}
        return 0;
    }

    public function getUserVotePoints(int $user_id): int
    {
        if (function_exists('points_get_main')) {
            $b = points_get_main($user_id);
            return (int)$b['vote'];
        }
        try {
            if ($this->tableExists('accounts') && $this->hasColumn('accounts', 'vp')) {
                $st = $this->db->prepare("SELECT COALESCE(vp,0) FROM accounts WHERE id = ?");
                $st->execute([$user_id]);
                return (int)$st->fetchColumn();
            }
        } catch (\Throwable $e) {}
        return 0;
    }

    /**
     * Get total amount spent by user.
     * Sum all payment_amount where account_id matches
     * Excludes only: Refunded, Denied, Failed, Reversed, Canceled_Reversal
     * Uses auth.donation_logs table
     */
    public function getUserTotalSpent(int $user_id): float
{
    try {
        $total = 0.0;

        // PRIORITY 1: auth.donation_logs (real PayPal data from auth database)
        try {
            $authPdo = $GLOBALS['authPdo'] ?? null;
            if ($authPdo instanceof PDO) {
                $st = $authPdo->prepare("
                    SELECT COALESCE(SUM(payment_amount), 0) AS total
                    FROM donation_logs
                    WHERE account_id = ? 
                    AND payment_status NOT IN ('Refunded', 'Denied', 'Failed', 'Reversed', 'Canceled_Reversal')
                ");
                $st->execute([$user_id]);
                $logTotal = (float)$st->fetchColumn();
                error_log("getUserTotalSpent: donation_logs total for user $user_id = $logTotal");
                $total += $logTotal;
            }
        } catch (\Throwable $e) {
            error_log("getUserTotalSpent: Could not query donation_logs: " . $e->getMessage());
        }

        // PRIORITY 2: thorium_website.donations table (new PayPal Standard donations)
        try {
            if ($this->tableExists('donations') && 
                $this->columnsExist('donations', ['user_id', 'amount', 'status'])) {
                
                $statusCheck = $this->hasColumn('donations', 'status') 
                    ? " AND (status = 'completed' OR status IS NULL OR status = '')" 
                    : "";
                
                $st = $this->db->prepare("
                    SELECT COALESCE(SUM(amount), 0) AS total
                    FROM donations
                    WHERE user_id = ? {$statusCheck}
                ");
                $st->execute([$user_id]);
                $donTotal = (float)$st->fetchColumn();
                error_log("getUserTotalSpent: donations table total for user $user_id = $donTotal");
                $total += $donTotal;
            }
        } catch (\Throwable $e) {
            error_log("getUserTotalSpent: Could not query donations table: " . $e->getMessage());
        }

        // Return combined total
        error_log("getUserTotalSpent: Combined total for user $user_id = $total");
        if ($total > 0) {
            return $total;
        }

        // FALLBACK: accounts.total_spent if both above don't exist or return 0
        try {
            if ($this->tableExists('accounts') && $this->hasColumn('accounts', 'total_spent')) {
                $st = $this->db->prepare("SELECT COALESCE(total_spent, 0) FROM accounts WHERE id = ?");
                $st->execute([$user_id]);
                $fallbackTotal = (float)$st->fetchColumn();
                error_log("getUserTotalSpent: Using fallback accounts.total_spent = $fallbackTotal");
                return $fallbackTotal;
            }
        } catch (\Throwable $e) {
            error_log("getUserTotalSpent: Could not query accounts fallback: " . $e->getMessage());
        }

    } catch (\Throwable $e) {
        error_log("getUserTotalSpent FATAL error: " . $e->getMessage());
    }
    
    return 0.0;
}

    /* ---------- Donation history (schema-aware, no probe-by-error) ---------- */

    /**
     * Get user's donation history.
     * PRIORITY 1: donation_logs (real PayPal data) - matches by account_id, payer_id, or account name
     * PRIORITY 2: donations table (standard schema)
     * PRIORITY 3: donation_history (legacy)
     */
    public function getUserDonationHistory(int $user_id, int $limit = 5, bool $completedOnly = true): array
    {
        $uid = (int)$user_id;
        $lim = $this->clampLimit($limit, 50);

        try {
            // PRIORITY 1: donation_logs (real PayPal data)
            if ($this->tableExists('donation_logs') && 
                $this->columnsExist('donation_logs', ['payment_amount', 'date'])) {
                
                // Get account info to match against payer_id and account name
                $accountInfo = null;
                if ($this->tableExists('accounts')) {
                    $st = $this->db->prepare("SELECT id, username FROM accounts WHERE id = ? LIMIT 1");
                    $st->execute([$uid]);
                    $accountInfo = $st->fetch(PDO::FETCH_ASSOC);
                }

                // Build WHERE clause to match by account_id, payer_id, or account name
                $whereConditions = ['account_id = ?'];
                $params = [$uid];

                if ($accountInfo) {
                    // Also match by payer_id
                    $whereConditions[] = 'payer_id = ?';
                    $params[] = (string)$accountInfo['id'];
                    
                    // Also match by account name
                    $whereConditions[] = 'account = ?';
                    $params[] = (string)$accountInfo['username'];
                }

                $whereClause = '(' . implode(' OR ', $whereConditions) . ')';
                $statusWhere = $completedOnly ? " AND payment_status NOT IN ('Refunded', 'Denied', 'Failed', 'Reversed', 'Canceled_Reversal') " : '';
                
                $sql = "SELECT 
                            payment_amount AS amount,
                            COALESCE(date, date_stamp) AS created_at,
                            COALESCE(points_added, 0) AS points_earned,
                            payment_status AS status
                        FROM donation_logs
                        WHERE {$whereClause} {$statusWhere}
                        ORDER BY date DESC, date_stamp DESC
                        LIMIT {$lim}";
                $st = $this->db->prepare($sql);
                $st->execute($params);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                if (!empty($rows)) {
                    return $rows;
                }
            }

            // PRIORITY 2: donations table (standard schema)
            if ($this->tableExists('donations') &&
                $this->columnsExist('donations', ['amount','created_at','points_earned'])) {

                $hasStatus   = $this->hasColumn('donations', 'status');
                $statusField = $hasStatus ? ', status' : '';
                
                $statusWhere = '';
                if ($completedOnly && $hasStatus) {
                    $statusWhere = " AND (UPPER(status)='COMPLETED' OR UPPER(status)='VERIFIED' OR status IS NULL OR status='') ";
                }

                $sql = "SELECT amount, created_at, points_earned{$statusField}
                        FROM donations
                        WHERE user_id = ? {$statusWhere}
                        ORDER BY created_at DESC
                        LIMIT {$lim}";
                $st = $this->db->prepare($sql);
                $st->execute([$uid]);
                return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }

            // PRIORITY 2b: donations(amount, date_created, points)
            if ($this->tableExists('donations') &&
                $this->columnsExist('donations', ['amount','date_created','points'])) {

                $sql = "SELECT amount, date_created AS created_at, points AS points_earned
                        FROM donations
                        WHERE user_id = ?
                        ORDER BY date_created DESC
                        LIMIT {$lim}";
                $st = $this->db->prepare($sql);
                $st->execute([$uid]);
                return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }

            // PRIORITY 3: donation_history (legacy)
            if ($this->tableExists('donation_history') &&
                $this->columnsExist('donation_history', ['amount','timestamp','donation_points'])) {

                $sql = "SELECT amount, `timestamp` AS created_at, donation_points AS points_earned
                        FROM donation_history
                        WHERE user_id = ?
                        ORDER BY `timestamp` DESC
                        LIMIT {$lim}";
                $st = $this->db->prepare($sql);
                $st->execute([$uid]);
                return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }

            return [];
        } catch (\Throwable $e) {
            error_log("Donation history error: " . $e->getMessage());
            return [];
        }
    }

    /* ---------- Leaderboards / stats ---------- */

    /**
     * Get top donors.
     * PRIORITY 1: donation_logs (real source)
     * PRIORITY 2: accounts.total_spent
     */
    public function getTopDonors(int $limit = 10): array
    {
        $lim = $this->clampLimit($limit, 50);

        try {
            // PRIORITY 1: donation_logs
            if ($this->tableExists('auth.donation_logs') && $this->hasColumn('auth.donation_logs', 'payment_amount')) {
                $sql = "
                    SELECT a.username,
                           SUM(dl.payment_amount) AS total_spent,
                           SUM(COALESCE(dl.points_added, 0)) AS donation_points
                    FROM auth.donation_logs dl
                    LEFT JOIN accounts a ON a.id = dl.account_id
                    WHERE (dl.payment_status NOT IN ('Refunded', 'Denied', 'Failed', 'Reversed', 'Canceled_Reversal'))
                    GROUP BY dl.account_id
                    ORDER BY total_spent DESC
                    LIMIT {$lim}
                ";
                $st = $this->db->query($sql);
                $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
                if (!empty($rows)) {
                    return $rows;
                }
            }

            // PRIORITY 2: accounts.total_spent
            if ($this->tableExists('accounts') && $this->hasColumn('accounts', 'total_spent')) {
                $sql = "
                    SELECT username,
                           COALESCE(total_spent, 0) AS total_spent,
                           COALESCE(dp, 0) AS donation_points
                    FROM accounts
                    WHERE COALESCE(total_spent, 0) > 0
                    ORDER BY total_spent DESC
                    LIMIT {$lim}
                ";
                $st = $this->db->query($sql);
                return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            }

            return [];
        } catch (\Throwable $e) {
            error_log("Top donors error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get donation statistics.
     * PRIORITY 1: donation_logs (real source)
     * PRIORITY 2: accounts.total_spent
     */
    public function getDonationStats(): array
    {
        try {
            $supporters = 0;
            $totalAmount = 0.0;
            $totalPoints = 0;

            // PRIORITY 1: donation_logs (real source)
            if ($this->tableExists('donation_logs') && $this->hasColumn('donation_logs', 'payment_amount')) {
                $st = $this->db->query("
                    SELECT COUNT(DISTINCT account_id) AS supporters,
                           SUM(payment_amount) AS total_amount
                    FROM donation_logs
                    WHERE payment_status NOT IN ('Refunded', 'Denied', 'Failed')
                ");
                $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : null;
                $supporters  = (int)($row['supporters'] ?? 0);
                $totalAmount = (float)($row['total_amount'] ?? 0.0);

                // Get total points from donation_logs
                $st = $this->db->query("
                    SELECT COALESCE(SUM(points_added), 0) AS total_points
                    FROM donation_logs
                    WHERE payment_status NOT IN ('Refunded', 'Denied', 'Failed')
                ");
                $totalPoints = (int)($st ? $st->fetchColumn() : 0);

                return [
                    'total_donations'      => $supporters,
                    'total_amount'         => $totalAmount,
                    'unique_donors'        => $supporters,
                    'total_points_awarded' => $totalPoints,
                ];
            }

            // PRIORITY 2: Fall back to accounts.total_spent
            if ($this->tableExists('accounts') && $this->hasColumn('accounts', 'total_spent')) {
                $st = $this->db->query("
                    SELECT COUNT(*) AS supporters,
                           SUM(total_spent) AS total_amount
                    FROM accounts
                    WHERE COALESCE(total_spent, 0) > 0
                ");
                $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : null;
                $supporters  = (int)($row['supporters'] ?? 0);
                $totalAmount = (float)($row['total_amount'] ?? 0.0);
            }

            // Points count
            if ($this->tableExists('accounts') && $this->hasColumn('accounts', 'dp')) {
                $st = $this->db->query("SELECT SUM(COALESCE(dp,0)) FROM accounts");
                $totalPoints = (int)($st ? $st->fetchColumn() : 0);
            }

            return [
                'total_donations'      => $supporters,
                'total_amount'         => $totalAmount,
                'unique_donors'        => $supporters,
                'total_points_awarded' => $totalPoints,
            ];
        } catch (\Throwable $e) {
            error_log("Donation stats error: " . $e->getMessage());
            return [
                'total_donations'      => 0,
                'total_amount'         => 0.0,
                'unique_donors'        => 0,
                'total_points_awarded' => 0,
            ];
        }
    }

    /* ---------- Recording / admin ops ---------- */

    public function recordDonation(
        int $user_id,
        float $amount,
        string $currency,
        string $transaction_id,
        string $status = 'completed'
    ): bool {
        try {
            $this->db->beginTransaction();

            // Package lookup (optional, to fix points_earned on the row)
            $packages = $this->getDonationPackages();
            $points_earned = (int)round($amount * 100); // default 100 / $
            foreach ($packages as $p) {
                if (abs((float)$p['amount'] - $amount) < 0.01) {
                    $points_earned = (int)$p['total_points'];
                    break;
                }
            }

            // Insert donation row
            if ($this->tableExists('donations') &&
                $this->columnsExist('donations', ['user_id','amount','currency','transaction_id','status','created_at','points_earned'])) {
                $st = $this->db->prepare("
                    INSERT INTO donations (user_id, amount, currency, transaction_id, status, created_at, points_earned)
                    VALUES (?, ?, ?, ?, ?, NOW(), ?)
                ");
                $st->execute([$user_id, $amount, $currency, $transaction_id, $status, $points_earned]);
            }

            // ✅ Only award points & totals immediately if this is already completed
            if ($status === 'completed') {
                $this->awardPointsAndTotals($user_id, $points_earned, $amount);
            }

            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log("DONATION ERROR: " . $e->getMessage());
            return false;
        }
    }

    /** Idempotent finalizer for IPN/webhook: flips to 'completed' and awards points exactly once. */
    public function finalizeDonationByTransactionId(string $txId): bool
    {
        try {
            $this->db->beginTransaction();

            $st = $this->db->prepare("SELECT id, user_id, amount, points_earned, status FROM donations WHERE transaction_id = ? FOR UPDATE");
            $st->execute([$txId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) { $this->db->rollBack(); return false; }

            if (($row['status'] ?? '') !== 'completed') {
                // Flip to completed
                $up = $this->db->prepare("UPDATE donations SET status='completed', updated_at=CURRENT_TIMESTAMP WHERE id = ?");
                $up->execute([$row['id']]);

                // Award points & totals now
                $this->awardPointsAndTotals((int)$row['user_id'], (int)$row['points_earned'], (float)$row['amount']);
            }

            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log("DONATION FINALIZE ERROR: " . $e->getMessage());
            return false;
        }
    }

    /** Shared helper – performs the actual crediting to points/total_spent. */
    private function awardPointsAndTotals(int $user_id, int $points, float $amount): void
    {
        error_log("=== awardPointsAndTotals called for user $user_id, points=$points, amount=$amount ===");

        // Smart points integration
        global $authPdo;

        $pointsAwarded = false;
        if ($authPdo && function_exists('points_add_donation_auth')) {
            $pointsAwarded = points_add_donation_auth($authPdo, $user_id, $points);
            error_log("points_add_donation_auth result: " . ($pointsAwarded ? "true" : "false"));
        }
        if (!$pointsAwarded && function_exists('points_add_donation_smart')) {
            $pointsAwarded = points_add_donation_smart($authPdo, $this->db, $user_id, $points);
            error_log("points_add_donation_smart result: " . ($pointsAwarded ? "true" : "false"));
        }
        if (!$pointsAwarded && $this->tableExists('accounts') && $this->hasColumn('accounts','dp')) {
            $st = $this->db->prepare("UPDATE accounts SET dp = COALESCE(dp,0) + ? WHERE id = ?");
            $st->execute([$points, $user_id]);
            error_log("Updated accounts.dp directly");
        }

        // Update thorium_website.accounts.total_spent with sum from BOTH new and old donations
        if ($this->tableExists('accounts') && $this->hasColumn('accounts', 'total_spent')) {
            try {
                error_log("Attempting to update accounts.total_spent for user $user_id from both donation sources");

                $totalSpent = 0.0;

                // PRIORITY 1: thorium_website.donations (new PayPal Standard donations)
                try {
                    $st = $this->db->prepare("
                        SELECT COALESCE(SUM(amount), 0) as total
                        FROM donations
                        WHERE user_id = ? AND status = 'completed'
                    ");
                    $st->execute([$user_id]);
                    $newDonations = (float)$st->fetchColumn();
                    $totalSpent += $newDonations;
                    error_log("New donations (thorium_website.donations): $newDonations");
                } catch (\Throwable $e) {
                    error_log("Could not query thorium_website.donations: " . $e->getMessage());
                }

                // PRIORITY 2: auth.donation_logs (old PayPal donations)
                try {
                    if ($authPdo instanceof PDO) {
                        $st = $authPdo->prepare("
                            SELECT COALESCE(SUM(payment_amount), 0) as total
                            FROM donation_logs
                            WHERE account_id = ? AND payment_status NOT IN ('Refunded', 'Denied', 'Failed', 'Reversed', 'Canceled_Reversal')
                        ");
                        $st->execute([$user_id]);
                        $oldDonations = (float)$st->fetchColumn();
                        $totalSpent += $oldDonations;
                        error_log("Old donations (auth.donation_logs): $oldDonations");
                    }
                } catch (\Throwable $e) {
                    error_log("Could not query auth.donation_logs: " . $e->getMessage());
                }

                // Update thorium_website.accounts with combined total
                error_log("Combined total_spent: $totalSpent");
                $st = $this->db->prepare("UPDATE accounts SET total_spent = ? WHERE id = ?");
                $st->execute([$totalSpent, $user_id]);
                $rows = $st->rowCount();
                error_log("Updated accounts.total_spent for user $user_id (rows affected: $rows, amount: $totalSpent)");

            } catch (\Throwable $e) {
                error_log("FATAL: Could not update accounts.total_spent: " . $e->getMessage());
                error_log("Stack trace: " . $e->getTraceAsString());
            }
        } else {
            error_log("WARNING: accounts table or total_spent column not found. Table exists: " . ($this->tableExists('accounts') ? "yes" : "no"));
        }
    }

    public function getUserInfo(int $user_id): ?array
    {
        try {
            if (!$this->tableExists('accounts')) return null;

            // Build a minimal select based on available columns
            $cols = ['id','username','email','created_at'];
            foreach (['vp','dp','total_spent'] as $c) {
                if ($this->hasColumn('accounts', $c)) $cols[] = $c;
            }
            $colList = implode(',', array_map(static fn($c) => "COALESCE($c,0) AS $c", $cols));
            $colList = str_replace('COALESCE(id,0) AS id', 'id', $colList); // id should not be COALESCE

            $st = $this->db->prepare("SELECT $colList FROM accounts WHERE id = ?");
            $st->execute([$user_id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);

            if ($row && function_exists('points_get_main')) {
                $b = points_get_main($user_id);
                if (array_key_exists('vp', $row)) $row['vp'] = (int)$b['vote'];
                if (array_key_exists('dp', $row)) $row['dp'] = (int)$b['donation'];
            }
            return $row ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function addDonationPoints(int $user_id, int $points, string $reason = 'Manual adjustment'): bool
{
    try {
        if ($points <= 0) {
            error_log("Points award skipped: points <= 0 ($points)");
            return false;
        }

        // 
        // Get auth database connection
        // 
        
        $authPdo = $GLOBALS['authPdo'] ?? null;
        
        if (!$authPdo instanceof PDO) {
            error_log("ERROR: authPdo not available in GLOBALS");
            return false;
        }

        error_log("Attempting to award $points dp to user $user_id");

        // 
        // Update auth.account.dp
        // 
        
        $stmt = $authPdo->prepare("
            UPDATE account 
            SET dp = COALESCE(dp, 0) + ? 
            WHERE id = ?
        ");
        
        $result = $stmt->execute([$points, $user_id]);
        
        if (!$result) {
            error_log("ERROR: Failed to execute points update for user $user_id");
            return false;
        }

        $rowsAffected = $stmt->rowCount();
        
        if ($rowsAffected === 0) {
            error_log("WARNING: No rows updated - user $user_id may not exist in auth.account");
            return false;
        }

        error_log("SUCCESS: Awarded $points dp to user $user_id (rows affected: $rowsAffected)");

        // 
        // Optional: Log to donations table for audit trail
        // 
        
        try {
            if ($this->tableExists('donations') &&
                $this->columnsExist('donations', ['user_id','amount','currency','transaction_id','status','created_at','points_earned'])) {
                $stmt = $this->db->prepare("
                    INSERT INTO donations (user_id, amount, currency, transaction_id, status, created_at, points_earned)
                    VALUES (?, 0.00, 'USD', ?, 'points_award', NOW(), ?)
                ");
                $stmt->execute([$user_id, 'POINTS_' . time(), $points]);
                error_log("Logged points award to donations audit trail");
            }
        } catch (Throwable $e) {
            // Audit logging failure is not fatal
            error_log("Note: Could not log to audit trail: " . $e->getMessage());
        }

        return true;

    } catch (Throwable $e) {
        error_log("CRITICAL: addDonationPoints exception: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        return false;
    }
}

    public function spendDonationPoints(int $user_id, int $points, string $item_name = 'Unknown item'): bool
    {
        try {
            // Prefer auth spend if available
            global $authPdo;
            if ($authPdo && function_exists('points_spend_donation_auth')) {
                if (points_spend_donation_auth($authPdo, $user_id, $points)) {
                    return true;
                }
            }

            // Legacy fallback with balance check
            $this->db->beginTransaction();

            $current = $this->getUserDonationPoints($user_id);
            if ($current < $points) {
                $this->db->rollBack();
                return false;
            }

            if ($this->tableExists('accounts') && $this->hasColumn('accounts', 'dp')) {
                $st = $this->db->prepare("UPDATE accounts SET dp = dp - ? WHERE id = ?");
                $st->execute([$points, $user_id]);
            }

            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log("DONATION SPEND ERROR: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get VIP leaderboard based on total_spent.
     * Uses donation_logs as priority source.
     */
    public function getVipLeaderboard(int $limit = 10): array
    {
        $lim = $this->clampLimit($limit, 50);

        try {
            // PRIORITY 1: Use donation_logs data
            if ($this->tableExists('donation_logs') && $this->hasColumn('donation_logs', 'payment_amount')) {
                $sql = "
                    SELECT a.username,
                           SUM(dl.payment_amount) AS total_spent,
                           COALESCE(a.dp, 0) AS current_points,
                           FLOOR(SUM(dl.payment_amount) / 20) AS vip_level
                    FROM donation_logs dl
                    LEFT JOIN accounts a ON a.id = dl.account_id
                    WHERE dl.payment_status IN ('Completed', 'completed', 'Verified')
                    GROUP BY dl.account_id
                    ORDER BY total_spent DESC
                    LIMIT {$lim}
                ";
                $st = $this->db->query($sql);
                $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
                if (!empty($rows)) {
                    // Replace current_points with smart value if available
                    if (function_exists('points_get_main')) {
                        foreach ($rows as &$r) {
                            $q = $this->db->prepare("SELECT id FROM accounts WHERE username = ? LIMIT 1");
                            $q->execute([$r['username']]);
                            $uid = (int)$q->fetchColumn();
                            if ($uid) {
                                $b = points_get_main($uid);
                                $r['current_points'] = (int)$b['donation'];
                            }
                        }
                        unset($r);
                    }
                    return $rows;
                }
            }

            // PRIORITY 2: Fall back to accounts.total_spent
            if (!$this->tableExists('accounts') || !$this->hasColumn('accounts','total_spent')) {
                return [];
            }

            $hasDp = $this->hasColumn('accounts','dp');
            $dpExpr = $hasDp ? 'COALESCE(dp,0)' : '0';

            $sql = "
                SELECT username,
                       COALESCE(total_spent,0) AS total_spent,
                       {$dpExpr} AS current_points,
                       FLOOR(COALESCE(total_spent,0) / 20) AS vip_level
                FROM accounts
                WHERE COALESCE(total_spent,0) > 0
                ORDER BY total_spent DESC, {$dpExpr} DESC
                LIMIT {$lim}
            ";
            $st = $this->db->query($sql);
            $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];

            if ($rows && function_exists('points_get_main')) {
                // Replace current_points with smart value
                foreach ($rows as &$r) {
                    $q = $this->db->prepare("SELECT id FROM accounts WHERE username = ? LIMIT 1");
                    $q->execute([$r['username']]);
                    $uid = (int)$q->fetchColumn();
                    if ($uid) {
                        $b = points_get_main($uid);
                        $r['current_points'] = (int)$b['donation'];
                    }
                }
                unset($r);
            }

            return $rows ?: [];
        } catch (\Throwable $e) {
            error_log("VIP LEADERBOARD ERROR: " . $e->getMessage());
            return [];
        }
    }
}