<?php
declare(strict_types=1);

/**
 * VoteRepository - Callback-based vote system with strict security
 * - Weekend bonus (Fri/Sat/Sun) doubles points using the vote's timestamp
 * - Cooldown computed via DB time
 * - Persists v.points_earned at confirm-time (callback/manual/auto)
 * - 30-day stats are weekend-aware (SQL DAYOFWEEK)
 * - No bound params in LIMIT (works w/ or w/o PDO emulation)
 */
class VoteRepository {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        if (!function_exists('points_get_main')) {
            $points_repo_path = __DIR__ . '/../points_repo.php';
            if (file_exists($points_repo_path)) require_once $points_repo_path;
        }
    }

    /* ===== Helpers ===== */

    private function weekendFactorFrom(string $datetime): float {
        // PHP: date('N') => 1=Mon ... 7=Sun
        $dow = (int)date('N', strtotime($datetime));
        return in_array($dow, [5,6,7], true) ? 2.0 : 1.0; // Fri/Sat/Sun
    }
    private function weekendFactorNow(): float {
        $dow = (int)date('N');
        return in_array($dow, [5,6,7], true) ? 2.0 : 1.0;
    }
    private function clampLimit(int $limit, int $min = 1, int $max = 100): int {
        return max($min, min($max, $limit));
    }
    private function computeAward(int $points, float $mult, string $votedAt): int {
        $wf = $this->weekendFactorFrom($votedAt);
        return (int)floor($points * $mult * $wf);
    }

    /* ===== Sites ===== */

    public function getVoteSites(?int $userId = null): array {
        try {
            $stmt = $this->pdo->prepare("
                SELECT id, title, link, logo, points, multiplier, hour_interval,
                       callback, callback_var, callback_enabled, auto_confirm_hours, enabled
                FROM vote_sites
                WHERE enabled = 1
                ORDER BY title ASC
            ");
            $stmt->execute();

            $sites = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $rowId = (int)$row['id'];
                $url   = $userId !== null ? $this->buildVoteUrl($row, $userId) : $row['link'];

                $sites[$rowId] = [
                    'id' => $rowId,
                    'name' => $row['title'],
                    'url' => $url,
                    'base_url' => $row['link'],
                    'logo' => $row['logo'],
                    'points' => (int)$row['points'],
                    'multiplier' => (float)$row['multiplier'],
                    'hour_interval' => (int)$row['hour_interval'],
                    'callback' => $row['callback'],
                    'callback_var' => $row['callback_var'],
                    'callback_enabled' => (bool)$row['callback_enabled'],
                    'auto_confirm_hours' => (int)($row['auto_confirm_hours'] ?? 2),
                ];
            }
            return $sites;
        } catch (PDOException $e) {
            error_log("Vote sites fetch error: " . $e->getMessage());
            return [];
        }
    }

    private function buildVoteUrl(array $site, int $userId): string {
        $base    = (string)($site['link'] ?? '');
        $pattern = (string)($site['callback'] ?? '');
        $param   = trim((string)($site['callback_var'] ?? ''));

        $frag = '';
        $hashPos = strpos($base, '#');
        if ($hashPos !== false) { $frag = substr($base, $hashPos); $base = substr($base, 0, $hashPos); }

        if ($pattern === '-') return rtrim($base, '/') . '-' . $userId . $frag;
        if ($pattern === '/') return rtrim($base, '/') . '/' . $userId . $frag;
        if ($pattern !== '' && $pattern[0] === '&') {
            $sep = str_contains($base, '?') ? '&' : '?';
            return $base . $sep . ltrim($pattern, '&') . rawurlencode((string)$userId) . $frag;
        }
        if ($param !== '') {
            $sep = str_contains($base, '?') ? '&' : '?';
            return $base . $sep . rawurlencode($param) . '=' . rawurlencode((string)$userId) . $frag;
        }
        return $base . $frag;
    }

    /* ===== Cooldown ===== */

    public function hasVotedRecently(int $userId, int $siteId): bool {
        $res = $this->getTimeUntilNextVote($userId, $siteId);
        return !$res['can_vote'];
    }

    public function getTimeUntilNextVote(int $userId, int $siteId): array {
        try {
            $sql = "
                SELECT
                    COALESCE(NULLIF(CAST(vs.hour_interval AS SIGNED), 0), 12) AS hours,
                    UNIX_TIMESTAMP(MAX(v.voted_at)) AS last_ts,
                    UNIX_TIMESTAMP(NOW())            AS now_ts,
                    GREATEST(
                        0,
                        (UNIX_TIMESTAMP(MAX(v.voted_at)) + (COALESCE(NULLIF(CAST(vs.hour_interval AS SIGNED), 0), 12) * 3600))
                        - UNIX_TIMESTAMP(NOW())
                    ) AS remaining
                FROM vote_sites vs
                LEFT JOIN votes v
                  ON v.site_id = vs.id
                 AND v.account_id = :uid
                WHERE vs.id = :sid
                GROUP BY vs.id
                LIMIT 1
            ";
            $st = $this->pdo->prepare($sql);
            $st->execute([':uid' => $userId, ':sid' => $siteId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);

            if (!$row) return ['can_vote' => true, 'time_remaining' => 0];

            $remaining = (int)$row['remaining'];
            return [
                'can_vote'       => ($remaining === 0),
                'time_remaining' => $remaining,
            ];
        } catch (PDOException $e) {
            error_log("NEXT VOTE TIME ERROR: " . $e->message());
            return ['can_vote' => true, 'time_remaining' => 0];
        }
    }

    /* ===== Voting ===== */

    public function startVote(int $userId, int $siteId, string $userIp): array {
        try {
            error_log("VOTE START: user={$userId} site={$siteId} ip={$userIp}");

            $stmt = $this->pdo->prepare("SELECT id, username FROM accounts WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            if (!$user) return ['success' => false, 'message' => 'Invalid user account'];

            if ($this->hasVotedRecently($userId, $siteId)) {
                return ['success' => false, 'message' => 'You must wait before voting on this site again!'];
            }

            $stmt = $this->pdo->prepare("
                SELECT id, title, points, multiplier, callback_enabled, auto_confirm_hours, enabled
                FROM vote_sites WHERE id = ? AND enabled = 1
            ");
            $stmt->execute([$siteId]);
            $site = $stmt->fetch();
            if (!$site) return ['success' => false, 'message' => 'Invalid vote site selected.'];

            $points = (int)$site['points'];
            $mult   = (float)$site['multiplier'];
            $previewTotal = (int)floor($points * $mult * $this->weekendFactorNow());

            $this->pdo->prepare("
                INSERT INTO votes (account_id, site_id, voted_at, ip_address, status)
                VALUES (?, ?, NOW(), ?, 'pending')
            ")->execute([$userId, $siteId, $userIp]);
            $voteId = $this->pdo->lastInsertId();

            return [
                'success'            => true,
                'message'            => "Vote started for {$site['title']}. Complete the vote on the site; points are automatic when it confirms.",
                'site_name'          => $site['title'],
                'points_to_earn'     => $previewTotal,
                'callback_enabled'   => (bool)$site['callback_enabled'],
                'auto_confirm_hours' => (int)$site['auto_confirm_hours'],
                'vote_url'           => $this->getVoteUrlForUser($siteId, $userId),
                'vote_id'            => $voteId,
                'user_id'            => $userId,
                'username'           => $user['username']
            ];
        } catch (PDOException $e) {
            error_log("VOTE START DB ERROR: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error occurred while starting vote.'];
        }
    }

    public function processCallbackStrict(int $userId, int $siteId, string $userIp = '', array $callbackData = []): array {
        try {
            $stmt = $this->pdo->prepare("
                SELECT v.id, v.voted_at, vs.points, vs.multiplier, vs.title, vs.hour_interval
                FROM votes v
                JOIN vote_sites vs ON v.site_id = vs.id
                WHERE v.account_id = ? AND v.site_id = ? AND v.status = 'pending'
                ORDER BY v.voted_at DESC
                LIMIT 1
            ");
            $stmt->execute([$userId, $siteId]);
            $vote = $stmt->fetch();
            if (!$vote) {
                return ['success' => false, 'message' => "No pending vote found. User must start vote first."];
            }

            $this->pdo->beginTransaction();

            $total = $this->computeAward((int)$vote['points'], (float)$vote['multiplier'], $vote['voted_at']);

            // Persist exact award for history/parity
            $this->pdo->prepare("
                UPDATE votes
                SET status='callback_confirmed', confirmed_at=NOW(), points_earned = :earned
                WHERE id = :vid
            ")->execute([':earned'=>$total, ':vid'=>$vote['id']]);

            // Credit the user (auth-aware if available)
            $pointsAwarded = false;
            global $authPdo;

            if ($authPdo && function_exists('points_add_vote_auth')) {
                $pointsAwarded = points_add_vote_auth($authPdo, $userId, $total);
            }
            if (!$pointsAwarded && function_exists('points_add_vote_smart')) {
                $pointsAwarded = points_add_vote_smart($authPdo, $this->pdo, $userId, $total);
            }
            if (!$pointsAwarded) {
                $this->pdo->prepare("UPDATE accounts SET vp = vp + ? WHERE id = ?")
                          ->execute([$total, $userId]);
            }

            // Read back new balance (pref to central aggregator)
            $newVp = 0;
            if (function_exists('points_get_main')) {
                $balances = points_get_main($userId);
                $newVp = $balances['vote'];
            } else {
                $stmt = $this->pdo->prepare("SELECT vp FROM accounts WHERE id = ?");
                $stmt->execute([$userId]);
                $newVp = (int)$stmt->fetchColumn();
            }

            $this->pdo->commit();

            return [
                'success' => true,
                'message' => "Callback processed! {$total} points awarded for {$vote['title']}.",
                'points_earned' => $total,
                'new_vp_balance' => $newVp,
                'multiplier_applied' => (float)$vote['multiplier'] * $this->weekendFactorFrom($vote['voted_at']),
                'weekend_bonus' => ($this->weekendFactorFrom($vote['voted_at']) > 1.0),
            ];
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            error_log("VOTE CALLBACK DB ERROR: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }

    public function processCallback(int $userId, int $siteId, string $userIp = '', array $callbackData = []): array {
        return $this->processCallbackStrict($userId, $siteId, $userIp, $callbackData);
    }

    public function confirmVote(int $userId, int $siteId): array {
        try {
            $stmt = $this->pdo->prepare("
                SELECT v.id, v.voted_at, vs.points, vs.multiplier, vs.title
                FROM votes v
                JOIN vote_sites vs ON v.site_id = vs.id
                WHERE v.account_id = ? AND v.site_id = ? AND v.status = 'pending'
                ORDER BY v.voted_at DESC LIMIT 1
            ");
            $stmt->execute([$userId, $siteId]);
            $vote = $stmt->fetch();
            if (!$vote) return ['success' => false, 'message' => 'No pending vote found.'];

            $this->pdo->beginTransaction();

            $total = $this->computeAward((int)$vote['points'], (float)$vote['multiplier'], $vote['voted_at']);

            // Persist exact award
            $this->pdo->prepare("
                UPDATE votes
                SET status='manual_confirmed', confirmed_at=NOW(), points_earned = :earned
                WHERE id = :vid
            ")->execute([':earned'=>$total, ':vid'=>$vote['id']]);

            // Credit user
            $pointsAwarded = false;
            global $authPdo;

            if ($authPdo && function_exists('points_add_vote_auth')) {
                $pointsAwarded = points_add_vote_auth($authPdo, $userId, $total);
            }
            if (!$pointsAwarded && function_exists('points_add_vote_smart')) {
                $pointsAwarded = points_add_vote_smart($authPdo, $this->pdo, $userId, $total);
            }
            if (!$pointsAwarded) {
                $this->pdo->prepare("UPDATE accounts SET vp = vp + ? WHERE id = ?")
                          ->execute([$total, $userId]);
            }

            $this->pdo->commit();
            return ['success' => true, 'message' => "Vote confirmed! +{$total} points."];
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            error_log("VOTE MANUAL CONFIRM ERROR: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error confirming vote: ' . $e->getMessage()];
        }
    }

    public function autoConfirmOldVotes(): int {
        try {
            $stmt = $this->pdo->prepare("
                SELECT v.id, v.account_id, v.voted_at, vs.points, vs.multiplier, vs.auto_confirm_hours, vs.title
                FROM votes v
                JOIN vote_sites vs ON v.site_id = vs.id
                WHERE v.status = 'pending'
                  AND v.voted_at <= DATE_SUB(NOW(), INTERVAL vs.auto_confirm_hours HOUR)
            ");
            $stmt->execute();

            $confirmed = 0;
            while ($vote = $stmt->fetch()) {
                $this->pdo->beginTransaction();
                try {
                    $userId = (int)$vote['account_id'];
                    $total  = $this->computeAward((int)$vote['points'], (float)$vote['multiplier'], $vote['voted_at']);

                    // Persist exact award
                    $this->pdo->prepare("
                        UPDATE votes
                        SET status='auto_confirmed', confirmed_at=NOW(), points_earned = :earned
                        WHERE id = :vid
                    ")->execute([':earned'=>$total, ':vid'=>$vote['id']]);

                    // Credit user
                    $pointsAwarded = false;
                    global $authPdo;

                    if ($authPdo && function_exists('points_add_vote_auth')) {
                        $pointsAwarded = points_add_vote_auth($authPdo, $userId, $total);
                    }
                    if (!$pointsAwarded && function_exists('points_add_vote_smart')) {
                        $pointsAwarded = points_add_vote_smart($authPdo, $this->pdo, $userId, $total);
                    }
                    if (!$pointsAwarded) {
                        $this->pdo->prepare("UPDATE accounts SET vp = vp + ? WHERE id = ?")
                                  ->execute([$total, $userId]);
                    }

                    $this->pdo->commit();
                    $confirmed++;
                } catch (Throwable $e) {
                    $this->pdo->rollBack();
                    error_log("VOTE AUTO CONFIRM ERROR: " . $e->getMessage());
                }
            }
            return $confirmed;
        } catch (PDOException $e) {
            error_log("VOTE AUTO CONFIRM OUTER ERROR: " . $e->getMessage());
            return 0;
        }
    }

    public function getUserPendingVotes(int $userId): array {
        try {
            $stmt = $this->pdo->prepare("
                SELECT v.*, vs.title AS site_name, vs.points, vs.multiplier, vs.callback_enabled, vs.auto_confirm_hours,
                       TIMESTAMPDIFF(MINUTE, v.voted_at, NOW()) AS minutes_ago,
                       (vs.auto_confirm_hours * 60) - TIMESTAMPDIFF(MINUTE, v.voted_at, NOW()) AS minutes_remaining
                FROM votes v
                JOIN vote_sites vs ON v.site_id = vs.id
                WHERE v.account_id = ?
                  AND v.status = 'pending'
                ORDER BY v.voted_at DESC
            ");
            $stmt->execute([$userId]);

            $out = [];
            while ($r = $stmt->fetch()) {
                $total = $this->computeAward((int)$r['points'], (float)$r['multiplier'], $r['voted_at']);
                $out[] = [
                    'site_id' => (int)$r['site_id'],
                    'site_name' => $r['site_name'],
                    'points_to_earn' => $total,
                    'voted_at' => $r['voted_at'],
                    'minutes_ago' => (int)$r['minutes_ago'],
                    'minutes_remaining' => max(0, (int)$r['minutes_remaining']),
                    'callback_enabled' => (bool)$r['callback_enabled'],
                ];
            }
            return $out;
        } catch (PDOException $e) {
            error_log("VOTE PENDING ERROR: " . $e->getMessage());
            return [];
        }
    }

    public function getUserVotePoints(int $userId): int {
        if (function_exists('points_get_main')) {
            $balances = points_get_main($userId);
            return $balances['vote'];
        }
        try {
            $stmt = $this->pdo->prepare("SELECT COALESCE(vp, 0) FROM accounts WHERE id = ?");
            $stmt->execute([$userId]);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("VOTE POINTS FETCH ERROR: " . $e->getMessage());
            return 0;
        }
    }

    public function getUserVoteHistory(int $userId, int $limit = 10): array {
        try {
            $uid = (int)$userId;
            $lim = $this->clampLimit($limit);

            $sql = "
                SELECT v.*, vs.title AS site_name, vs.points, vs.multiplier
                FROM votes v
                JOIN vote_sites vs ON v.site_id = vs.id
                WHERE v.account_id = {$uid}
                ORDER BY v.voted_at DESC
                LIMIT {$lim}
            ";
            $stmt = $this->pdo->query($sql);

            $history = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                // Prefer stored exact amount when confirmed; fallback to computed preview
                $confirmed = in_array($row['status'], ['callback_confirmed','manual_confirmed','auto_confirmed'], true);
                $earned    = $confirmed
                    ? (int)($row['points_earned'] ?? 0)
                    : $this->computeAward((int)$row['points'], (float)$row['multiplier'], $row['voted_at']);

                // If older rows predate this patch and points_earned is NULL, recompute:
                if ($confirmed && $earned <= 0) {
                    $earned = $this->computeAward((int)$row['points'], (float)$row['multiplier'], $row['voted_at']);
                }

                $history[] = [
                    'site_name'       => $row['site_name'],
                    'points_earned'   => $earned,
                    'voted_at'        => $row['voted_at'],
                    'status'          => $row['status'],
                    'confirmed_at'    => $row['confirmed_at'] ?? null,
                    'points'          => (int)$row['points'],
                    'multiplier'      => (float)$row['multiplier'],
                ];
            }
            return $history;
        } catch (PDOException $e) {
            error_log("VOTE HISTORY ERROR: " . $e->getMessage());
            return [];
        }
    }

    public function getTopVoters(int $limit = 10): array {
        try {
            if (function_exists('points_get_main')) {
                $stmt = $this->pdo->prepare("SELECT id, username, nickname FROM accounts ORDER BY id");
                $stmt->execute();
                $users = $stmt->fetchAll();

                $topVoters = [];
                foreach ($users as $user) {
                    $balances = points_get_main((int)$user['id']);
                    if ($balances['vote'] > 0) {
                        $topVoters[] = [
                            'username'     => $user['username'],
                            'nickname'     => $user['nickname'] ?? null,
                            'display_name' => $user['nickname'] ?? $user['username'],
                            'vote_points'  => $balances['vote'],
                        ];
                    }
                }
                usort($topVoters, fn($a,$b) => $b['vote_points'] <=> $a['vote_points']);
                return array_slice($topVoters, 0, $this->clampLimit($limit));
            }

            $lim = $this->clampLimit($limit);
            $sql = "
                SELECT 
                    a.username,
                    a.nickname,
                    COALESCE(a.nickname, a.username) AS display_name,
                    a.vp AS vote_points,
                    COUNT(CASE WHEN v.status IN ('callback_confirmed','manual_confirmed','auto_confirmed') THEN 1 END) AS confirmed_votes
                FROM accounts a
                LEFT JOIN votes v ON a.id = v.account_id
                WHERE a.vp > 0
                GROUP BY a.id, a.username, a.nickname, a.vp
                ORDER BY a.vp DESC, confirmed_votes DESC
                LIMIT {$lim}
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("TOP VOTERS ERROR: " . $e->getMessage());
            return [];
        }
    }

    public function getVoteStats(): array {
        try {
            // Weekend-aware totals via SQL (Sun=1, Fri=6, Sat=7 in DAYOFWEEK)
            $stmt = $this->pdo->prepare("
                SELECT 
                    COUNT(DISTINCT v.account_id) AS unique_voters,
                    COUNT(*) AS total_votes,
                    COUNT(CASE WHEN v.status IN ('callback_confirmed','manual_confirmed','auto_confirmed') THEN 1 END) AS confirmed_votes,
                    COUNT(CASE WHEN v.status = 'pending' THEN 1 END) AS pending_votes,
                    COUNT(CASE WHEN v.status = 'callback_confirmed' THEN 1 END) AS callback_confirmed,
                    COALESCE(SUM(CASE
                        WHEN v.status IN ('callback_confirmed','manual_confirmed','auto_confirmed')
                        THEN FLOOR(vs.points * vs.multiplier *
                             CASE WHEN DAYOFWEEK(v.voted_at) IN (1,6,7) THEN 2 ELSE 1 END)
                        ELSE 0
                    END),0) AS total_points_awarded
                FROM votes v
                JOIN vote_sites vs ON v.site_id = vs.id
                WHERE v.voted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
            $stmt->execute();
            $r = $stmt->fetch() ?: [];
            return [
                'unique_voters'        => (int)($r['unique_voters'] ?? 0),
                'total_votes'          => (int)($r['total_votes'] ?? 0),
                'confirmed_votes'      => (int)($r['confirmed_votes'] ?? 0),
                'pending_votes'        => (int)($r['pending_votes'] ?? 0),
                'callback_confirmed'   => (int)($r['callback_confirmed'] ?? 0),
                'total_points_awarded' => (int)($r['total_points_awarded'] ?? 0),
            ];
        } catch (PDOException $e) {
            error_log("VOTE STATS ERROR: " . $e->getMessage());
            return [
                'unique_voters' => 0, 'total_votes' => 0,
                'confirmed_votes' => 0, 'pending_votes' => 0,
                'callback_confirmed' => 0, 'total_points_awarded' => 0
            ];
        }
    }

    public function getVoteUrlForUser(int $siteId, int $userId): ?string {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM vote_sites WHERE id = ? AND enabled = 1");
            $stmt->execute([$siteId]);
            $site = $stmt->fetch();
            return $site ? $this->buildVoteUrl($site, $userId) : null;
        } catch (PDOException $e) {
            error_log("GET VOTE URL ERROR: " . $e->getMessage());
            return null;
        }
    }
}
