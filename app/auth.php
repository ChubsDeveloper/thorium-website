<?php
/**
 * app/auth.php
 * Authentication System
 * 
 * Comprehensive authentication system supporting dual-database architecture with
 * SRP6 authentication for game servers and bcrypt for website. Includes RBAC
 * permission management, legacy donation migration, nickname support, CSRF,
 * and password reset functionality.
 * 
 * NEW:
 * - sync_total_spent_from_auth(): keeps accounts.total_spent in sync with auth.donation_logs
 *   on every login (Completed payments only). Does NOT touch points.
 */

declare(strict_types=1);

require_once __DIR__ . '/nickname_helpers.php';
require_once __DIR__ . '/mailer.php';

// =============================================================================
// CSRF Protection
// =============================================================================

/** Generate or retrieve CSRF token for current session */
function csrf_token(): string {
  if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
  return $_SESSION['csrf'];
}

/** Verify CSRF token against session token */
function csrf_check(string $t): bool {
  return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
}

// =============================================================================
// Basic Authentication
// =============================================================================

/** Get currently authenticated user from session */
function auth_user(): ?array { return $_SESSION['user'] ?? null; }

/** Clear user session (logout) */
function auth_logout(): void { unset($_SESSION['user']); }

// =============================================================================
// RBAC Permission System
// =============================================================================

/**
 * RBAC Permission Levels:
 * - 190: Player
 * - 191: Trial GM  
 * - 192: Initiate GM
 * - 193: Senior GM
 * - 194: Head GM
 * - 195: Administrator
 * - 196: Staff Manager
 * - 197: Co-Owner
 * - 198: Owner
 */

/** Get user's highest RBAC permission level from database */
function auth_get_rbac_level(PDO $authPdo, int $accountId): int {
  $st = $authPdo->prepare("
    SELECT MAX(permissionId) AS max_perm
    FROM rbac_account_permissions
    WHERE accountId = :id AND permissionId BETWEEN 190 AND 198 AND granted = 1
  ");
  $st->execute([':id' => $accountId]);
  $row = $st->fetch();
  return (int)($row['max_perm'] ?? 190);
}

/** Check if user has minimum RBAC permission level */
function auth_has_rbac_permission(PDO $authPdo, int $accountId, int $minPermissionId): bool {
  return auth_get_rbac_level($authPdo, $accountId) >= $minPermissionId;
}

/** Check if user has administrative privileges */
function auth_is_admin(PDO $authPdo, int $accountId, int $minPermissionId = 191): bool {
  return auth_has_rbac_permission($authPdo, $accountId, $minPermissionId);
}

/** Get user's role name based on RBAC permission level */
function auth_get_role_name(PDO $authPdo, int $accountId): string {
  $level = auth_get_rbac_level($authPdo, $accountId);
  switch ($level) {
    case 198: return 'Owner';
    case 197: return 'Co-Owner';
    case 196: return 'Staff Manager';
    case 195: return 'Administrator';
    case 194: return 'Head GM';
    case 193: return 'Senior GM';
    case 192: return 'Initiate GM';
    case 191: return 'Trial GM';
    case 190:
    default:  return 'Player';
  }
}

/** Get comprehensive RBAC information for user */
function auth_get_rbac_info(PDO $authPdo, int $accountId): array {
  $level = auth_get_rbac_level($authPdo, $accountId);
  $role = auth_get_role_name($authPdo, $accountId);
  $minAdminLevel = (int)($GLOBALS['config']['admin_min_permission_id'] ?? 191);
  return [
    'permission_id' => $level,
    'role_name'     => $role,
    'is_admin'      => $level >= $minAdminLevel,
    'is_gm'         => $level >= 191 && $level <= 194,
    'is_staff'      => $level >= 195,
    'is_owner'      => $level >= 197,
  ];
}

/** Refresh user session with current RBAC information */
function auth_refresh_session_rbac(PDO $authPdo, int $minPermissionId = 191): void {
  if (empty($_SESSION['user']['id'])) return;
  $uid  = (int)$_SESSION['user']['id'];
  $rbac = auth_get_rbac_info($authPdo, $uid);
  $_SESSION['user']['rbac']     = $rbac;
  $_SESSION['user']['is_admin'] = auth_is_admin($authPdo, $uid, $minPermissionId);
}

// =============================================================================
// Legacy Donation Migration (one-time backfill + points)
// =============================================================================

/**
 * Migrate legacy donations for a user during login.
 * Reads auth.donation_logs and sets accounts.total_spent & adds DP **only if** current total_spent = 0.
 * Avoids double-granting points for users who already have a value.
 */
function migrate_legacy_donations_for_user(PDO $sitePdo, PDO $authPdo, int $user_id, string $username): bool {
  try {
    $stmt = $sitePdo->prepare("SELECT total_spent FROM accounts WHERE id = ?");
    $stmt->execute([$user_id]);
    $current_total = (float)($stmt->fetchColumn() ?: 0);

    if ($current_total > 0) {
      error_log("LEGACY MIGRATION: Skipping user {$username} (ID: {$user_id}) - already has total_spent: {$current_total}");
      return true;
    }

    // Try by username first, then fallback to account_id
    try {
      $stmt = $authPdo->prepare("
        SELECT 
          COALESCE(SUM(payment_amount),0) AS legacy_total,
          COALESCE(SUM(points_added),0)   AS legacy_points,
          COUNT(*)                        AS donation_count
        FROM donation_logs
        WHERE payment_status = 'Completed' AND LOWER(account) = LOWER(?)
      ");
      $stmt->execute([$username]);
      $result = $stmt->fetch(PDO::FETCH_ASSOC);

      $legacy_total  = (float)($result['legacy_total']  ?? 0);
      $legacy_points = (int)  ($result['legacy_points'] ?? 0);
      $donation_count= (int)  ($result['donation_count']?? 0);

      if ($legacy_total <= 0) {
        $stmt = $authPdo->prepare("
          SELECT 
            COALESCE(SUM(payment_amount),0) AS legacy_total,
            COALESCE(SUM(points_added),0)   AS legacy_points,
            COUNT(*)                        AS donation_count
          FROM donation_logs
          WHERE payment_status = 'Completed' AND account_id = ?
        ");
        $stmt->execute([$user_id]);
        $result        = $stmt->fetch(PDO::FETCH_ASSOC);
        $legacy_total  = (float)($result['legacy_total']  ?? 0);
        $legacy_points = (int)  ($result['legacy_points'] ?? 0);
        $donation_count= (int)  ($result['donation_count']?? 0);
      }
    } catch (PDOException $e) {
      error_log("LEGACY MIGRATION: Could not query donation_logs for {$username}: " . $e->getMessage());
      return false;
    }

    if ($legacy_total <= 0) {
      error_log("LEGACY MIGRATION: No legacy donations found for {$username} (ID: {$user_id})");
      return true;
    }

    $sitePdo->beginTransaction();
    try {
      // Backfill total_spent and points exactly once
      $stmt = $sitePdo->prepare("
        UPDATE accounts
        SET total_spent = :t, dp = COALESCE(dp,0) + :p
        WHERE id = :id
      ");
      $stmt->execute([':t'=>$legacy_total, ':p'=>$legacy_points, ':id'=>$user_id]);

      // Optional historical row (ignore if table missing)
      try {
        $stmt = $sitePdo->prepare("
          INSERT INTO donations (user_id, amount, currency, transaction_id, status, created_at, points_earned, migration_source)
          VALUES (?, ?, 'USD', ?, 'legacy_migration', NOW(), ?, 'legacy_2015_migration')
        ");
        $stmt->execute([$user_id, $legacy_total, 'LEGACY_MIGRATION_'.$user_id, $legacy_points]);
      } catch (PDOException $e) {
        error_log("LEGACY MIGRATION: Could not record history: " . $e->getMessage());
      }

      $sitePdo->commit();
      $vip_level = min(8, max(0, (int)floor($legacy_total / 25)));
      error_log("LEGACY MIGRATION SUCCESS: {$username} (ID: {$user_id}) - \${$legacy_total}, {$legacy_points} pts, {$donation_count} tx -> VIP {$vip_level}");
      return true;
    } catch (Throwable $e) {
      $sitePdo->rollBack();
      error_log("LEGACY MIGRATION ERROR: {$username} (ID: {$user_id}): " . $e->getMessage());
      return false;
    }
  } catch (Throwable $e) {
    error_log("LEGACY MIGRATION ERROR: Exception for {$username} (ID: {$user_id}): " . $e->getMessage());
    return false;
  }
}

// =============================================================================
// NEW: Idempotent sync of total_spent from auth.donation_logs (runs every login)
// =============================================================================

/**
 * Recompute & sync accounts.total_spent from auth.donation_logs.
 * - Includes rows where payment_status='Completed'
 * - Matches by LOWER(account) OR by account_id
 * - Safe to call every login; DOES NOT modify points.
 */
function sync_total_spent_from_auth(PDO $sitePdo, PDO $authPdo, int $userId, string $username): void {
  try {
    $st = $authPdo->prepare("
      SELECT COALESCE(SUM(payment_amount), 0) AS total
      FROM donation_logs
      WHERE payment_status = 'Completed'
        AND (LOWER(account) = LOWER(:u) OR account_id = :id)
    ");
    $st->execute([':u' => $username, ':id' => $userId]);
    $authTotal = (float)($st->fetchColumn() ?: 0.0);
  } catch (Throwable $e) {
    error_log('TOTAL_SPENT_SYNC: could not read auth.donation_logs: ' . $e->getMessage());
    return; // never block login
  }

  try {
    // Only update if it actually changed (tolerance for decimals)
    $st2 = $sitePdo->prepare("
      UPDATE accounts
      SET total_spent = :t
      WHERE id = :id
        AND (total_spent IS NULL OR ABS(total_spent - :t) > 0.009)
    ");
    $st2->execute([':t' => $authTotal, ':id' => $userId]);
  } catch (Throwable $e) {
    error_log('TOTAL_SPENT_SYNC: failed to update site.accounts: ' . $e->getMessage());
  }
}

// =============================================================================
// SRP6 Cryptographic Utilities (BCMath Implementation)
// =============================================================================

/** Ensure BCMath extension is loaded for SRP6 calculations */
function _ensure_bcmath(): void {
  if (!extension_loaded('bcmath')) {
    throw new RuntimeException('BCMath extension is required for SRP. Enable extension=php_bcmath.dll in php.ini and restart Apache.');
  }
  bcscale(0);
}

/** Convert hexadecimal string to decimal string using BCMath */
function _bc_hexdec(string $hex): string {
  $hex = strtolower(ltrim($hex, '0x'));
  if ($hex === '') return '0';
  $dec = '0';
  $len = strlen($hex);
  for ($i = 0; $i < $len; $i++) {
    $dec = bcmul($dec, '16', 0);
    $dec = bcadd($dec, (string)hexdec($hex[$i]), 0);
  }
  return $dec;
}

/** Convert decimal string to hexadecimal string using BCMath */
function _bc_dechex(string $dec): string {
  if ($dec === '' || $dec === '0') return '0';
  $hex = '';
  while (bccomp($dec, '0', 0) > 0) {
    $rem = bcmod($dec, '16');
    $hex = strtoupper(dechex((int)$rem)) . $hex;
    $dec = bcdiv($dec, '16', 0);
  }
  return $hex === '' ? '0' : $hex;
}

/** Fast modular exponentiation using square-and-multiply algorithm */
function _bc_powmod(string $base, string $exp, string $mod): string {
  $result = '1';
  $base = bcmod($base, $mod);
  while (bccomp($exp, '0') > 0) {
    if (bcmod($exp, '2') === '1') {
      $result = bcmod(bcmul($result, $base, 0), $mod);
    }
    $exp = bcdiv($exp, '2', 0);
    $base = bcmod(bcmul($base, $base, 0), $mod);
  }
  return $result;
}

/**
 * Compute SRP6 verifier for TrinityCore/AzerothCore authentication
 * TrinityCore SRP6 parameters:
 * - g = 7
 * - N = 0x894B...E9BB7 (256-bit)
 * Salt & verifier stored as 32-byte little-endian binary
 */
function srp6_compute_verifier(string $username, string $password, string $saltBin): string {
  _ensure_bcmath();

  $gDec = '7';
  $Nhex = '894B645E89E1535BBDAD5B8B290650530801B18EBFBF5E8FAB3C82872A3E9BB7';
  $NDec = _bc_hexdec($Nhex);

  $h1 = sha1(strtoupper($username) . ':' . strtoupper($password), true); // binary
  $h2 = sha1($saltBin . $h1, true);                                      // binary

  $h2hex  = bin2hex($h2); // big-endian
  $xLEhex = implode('', array_reverse(str_split($h2hex, 2))); // little-endian hex
  $xDec   = _bc_hexdec($xLEhex);

  $vDec   = _bc_powmod($gDec, $xDec, $NDec);

  $vHexBE = _bc_dechex($vDec);
  if ((strlen($vHexBE) % 2) !== 0) $vHexBE = '0'.$vHexBE;
  $vHexBE = strtoupper(str_pad($vHexBE, 64, '0', STR_PAD_LEFT));
  $vBinBE = hex2bin($vHexBE) ?: str_repeat("\x00", 32);
  $vBinLE = strrev($vBinBE); // store little-endian
  return $vBinLE;
}

// =============================================================================
// Account Registration
// =============================================================================

/**
 * Register new account in both auth and website databases
 * Creates account in both databases with matching IDs in a single transaction.
 */
function register_site_and_auth(array $conf, string $username, string $email, string $password, bool $preRegister = false): array {
  // Validate
  if (!preg_match('~^[A-Za-z0-9_]{3,32}$~', $username)) throw new InvalidArgumentException('Username must be 3-32 chars (A–Z, 0–9, _).');
  if (!filter_var($email, FILTER_VALIDATE_EMAIL))        throw new InvalidArgumentException('Invalid email.');
  if (strlen($password) < 6)                             throw new InvalidArgumentException('Password too short.');

  // Connections
  $siteDb = $conf['db']['name'];
  $authDb = $conf['auth_db']['name'];
  $server = pdo_server($conf['db']); // connects to host (no DB selected)
  if (!$server) throw new RuntimeException('DB server unavailable.');

  // Uniqueness across both DBs
  $qUser     = "SELECT 1 FROM {$authDb}.account  WHERE username = :u LIMIT 1";
  $qMail     = "SELECT 1 FROM {$authDb}.account  WHERE email    = :m LIMIT 1";
  $qUserSite = "SELECT 1 FROM {$siteDb}.accounts WHERE username = :u LIMIT 1";
  $qMailSite = "SELECT 1 FROM {$siteDb}.accounts WHERE email    = :m LIMIT 1";

  $server->beginTransaction();
  try {
    foreach ([$qUser, $qUserSite] as $sql) { $st = $server->prepare($sql); $st->execute([':u'=>$username]); if ($st->fetch()) throw new RuntimeException('Username already in use.'); }
    foreach ([$qMail, $qMailSite] as $sql) { $st = $server->prepare($sql); $st->execute([':m'=>$email]);    if ($st->fetch()) throw new RuntimeException('Email already in use.'); }

    // Prepare auth.account row (SRP6)
    $salt = random_bytes(32);
    $ver  = srp6_compute_verifier($username, $password, $salt);
    $now  = date('Y-m-d H:i:s');
    $ip   = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    $sqlAuth = "INSERT INTO {$authDb}.account
      (username, salt, verifier, email, reg_mail, joindate, last_ip, last_attempt_ip, failed_logins, locked, lock_country)
      VALUES (:u, :salt, :ver, :email1, :email2, :joined, :ip1, :ip2, 0, 0, '00')";
    $stA = $server->prepare($sqlAuth);
    $stA->bindParam(':u',      $username);
    $stA->bindParam(':salt',   $salt, PDO::PARAM_LOB);
    $stA->bindParam(':ver',    $ver,  PDO::PARAM_LOB);
    $stA->bindParam(':email1', $email);
    $stA->bindParam(':email2', $email);
    $stA->bindParam(':joined', $now);
    $stA->bindParam(':ip1',    $ip);
    $stA->bindParam(':ip2',    $ip);
    $stA->execute();

    $id   = (int)$server->lastInsertId();
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $preRegisterInt = $preRegister ? 1 : 0;

    $sqlSite = "INSERT INTO {$siteDb}.accounts (id, username, email, password_hash, vp, total_dp, pre_register, created_at)
                VALUES (:id, :u, :e, :h, 0, 0, :prereg, :d)";
    $stW = $server->prepare($sqlSite);
    $stW->execute([':id'=>$id, ':u'=>$username, ':e'=>$email, ':h'=>$hash, ':prereg'=>$preRegisterInt, ':d'=>$now]);

    // Default Player permission
    $sqlRbac = "INSERT IGNORE INTO {$authDb}.rbac_account_permissions (accountId, permissionId, granted, realmId)
                VALUES (:id, 190, 1, -1)";
    $stR = $server->prepare($sqlRbac);
    $stR->execute([':id'=>$id]);

    $server->commit();

    if ($preRegister) error_log("PRE-REGISTRATION: User '{$username}' (ID: {$id}) pre-registered via landing page");
    return ['id' => $id, 'username' => $username, 'pre_register' => $preRegister];
  } catch (Throwable $e) {
    $server->rollBack();
    throw $e;
  }
}

// =============================================================================
// Auth DB SRP Login + Site Account Provisioning
// =============================================================================

/** Authenticate user against auth database using SRP6 */
function auth_login_attempt(PDO $authPdo, string $username, string $password): ?array {
  $st = $authPdo->prepare("SELECT id, username, email, salt, verifier FROM account WHERE username = :u LIMIT 1");
  $st->execute([':u' => $username]);
  $row = $st->fetch();
  if (!$row) return null;

  $calc = srp6_compute_verifier($row['username'], $password, $row['salt']);
  if (!hash_equals($row['verifier'], $calc)) return null;

  return ['id' => (int)$row['id'], 'username' => $row['username'], 'email' => (string)($row['email'] ?? '')];
}

/** Ensure website account exists with matching auth account ID */
function ensure_site_account(PDO $sitePdo, array $acc, string $password): void {
  $hash = password_hash($password, PASSWORD_DEFAULT);
  $sql = "INSERT INTO accounts (id, username, email, password_hash, vp, total_dp, pre_register, created_at)
          VALUES (:id, :u, :e, :h, 0, 0, 0, NOW())
          ON DUPLICATE KEY UPDATE
            username = VALUES(username),
            email    = VALUES(email),
            password_hash = VALUES(password_hash)";
  $stmt = $sitePdo->prepare($sql);
  $stmt->execute([':id'=>$acc['id'], ':u'=>$acc['username'], ':e'=>$acc['email'], ':h'=>$hash]);

  // Ensure nickname exists
  ensure_user_nickname($sitePdo, $acc['id']);
}

// =============================================================================
// Hybrid Login (site-first, then SRP) + Sync total_spent every time
// =============================================================================

/**
 * Attempt login using website credentials (bcrypt)
 */
function site_login_attempt(PDO $sitePdo, PDO $authPdo, string $username, string $password, int $minPermissionId = 191): ?array {
  $st = $sitePdo->prepare("SELECT id, username, password_hash FROM accounts WHERE username = :u LIMIT 1");
  $st->execute([':u' => $username]);
  $row = $st->fetch();
  if (!$row || !password_verify($password, $row['password_hash'])) return null;

  $nickname = ensure_user_nickname($sitePdo, (int)$row['id']);

  $isAdmin  = auth_is_admin($authPdo, (int)$row['id'], $minPermissionId);
  $rbacInfo = auth_get_rbac_info($authPdo, (int)$row['id']);

  $_SESSION['user'] = [
    'id'        => (int)$row['id'],
    'username'  => $row['username'],
    'nickname'  => $nickname,
    'is_admin'  => $isAdmin,
    'rbac'      => $rbacInfo,
  ];

  // NEW: Always mirror latest Completed donations into site.accounts.total_spent
  sync_total_spent_from_auth($sitePdo, $authPdo, (int)$row['id'], (string)$row['username']);

  return $_SESSION['user'];
}

/**
 * Hybrid login system supporting both website and game authentication
 * - Tries site login first, then SRP6 auth DB
 * - JIT-provisions site account for existing game accounts
 * - Runs legacy donation migration once, then always syncs total_spent from auth
 */
function login_any(PDO $sitePdo, PDO $authPdo, array $config, string $username, string $password, int $minPermissionId = 191): ?array {
  // 1) Try normal site login
  $user = site_login_attempt($sitePdo, $authPdo, $username, $password, $minPermissionId);
  if ($user) return $user;

  // 2) Try auth DB SRP6
  $acc = auth_login_attempt($authPdo, $username, $password);
  if (!$acc) return null;

  // 3) JIT-provision into site DB with SAME id + new site hash
  ensure_site_account($sitePdo, $acc, $password);

  // 4) Ensure nickname
  $nickname = ensure_user_nickname($sitePdo, $acc['id']);

  // 5) One-time legacy migration (may add points if total_spent was 0)
  migrate_legacy_donations_for_user($sitePdo, $authPdo, $acc['id'], $acc['username']);

  // 6) Build session with RBAC info
  $isAdmin  = auth_is_admin($authPdo, $acc['id'], $minPermissionId);
  $rbacInfo = auth_get_rbac_info($authPdo, $acc['id']);

  $_SESSION['user'] = [
    'id'        => $acc['id'],
    'username'  => $acc['username'],
    'nickname'  => $nickname,
    'is_admin'  => $isAdmin,
    'rbac'      => $rbacInfo,
  ];

  // 7) NEW: Mirror latest Completed donations into site.accounts.total_spent
  sync_total_spent_from_auth($sitePdo, $authPdo, (int)$acc['id'], (string)$acc['username']);

  return $_SESSION['user'];
}

// =============================================================================
// Account Lookup + Password Reset Flow
// =============================================================================

/** Find account by username or email (prefers auth DB) */
function find_account(PDO $sitePdo, PDO $authPdo, string $identifier): ?array {
  $st = $authPdo->prepare("SELECT id, username, email FROM account WHERE username = :u LIMIT 1");
  $st->execute([':u' => $identifier]);
  $row = $st->fetch();
  if ($row) return ['id'=>(int)$row['id'], 'username'=>$row['username'], 'email'=>(string)$row['email']];

  $st = $authPdo->prepare("SELECT id, username, email FROM account WHERE email = :m LIMIT 1");
  $st->execute([':m' => $identifier]);
  $row = $st->fetch();
  if ($row) return ['id'=>(int)$row['id'], 'username'=>$row['username'], 'email'=>(string)$row['email']];

  $st = $sitePdo->prepare("SELECT id, username, email FROM accounts WHERE username = :u OR email = :m LIMIT 1");
  $st->execute([':u'=>$identifier, ':m'=>$identifier]);
  $row = $st->fetch();
  if ($row) return ['id'=>(int)$row['id'], 'username'=>$row['username'], 'email'=>(string)$row['email']];

  return null;
}

/** Create a one-time reset token (returns plaintext token) */
function create_reset_token(PDO $sitePdo, int $accountId, string $purpose='password', int $ttlMinutes=60): string {
  $token = bin2hex(random_bytes(32)); // 64 hex chars
  $hash  = hash('sha256', $token);
  $exp   = date('Y-m-d H:i:s', time() + $ttlMinutes*60);
  $sql   = "INSERT INTO password_resets (account_id, token_hash, purpose, expires_at) VALUES (:id, :h, :p, :e)";
  $st = $sitePdo->prepare($sql);
  $st->execute([':id'=>$accountId, ':h'=>$hash, ':p'=>$purpose, ':e'=>$exp]);
  return $token;
}

/** Validate token and fetch its row (with account). */
function get_token_row(PDO $sitePdo, PDO $authPdo, string $token, string $purpose='password'): ?array {
  $hash = hash('sha256', $token);
  $sql  = "SELECT * FROM password_resets WHERE token_hash = :h AND purpose = :p LIMIT 1";
  $st = $sitePdo->prepare($sql);
  $st->execute([':h'=>$hash, ':p'=>$purpose]);
  $row = $st->fetch();
  if (!$row) return null;
  if (!empty($row['used_at'])) return null;
  if (strtotime($row['expires_at']) < time()) return null;

  $st2 = $authPdo->prepare("SELECT id, username, email FROM account WHERE id = :id LIMIT 1");
  $st2->execute([':id'=>$row['account_id']]);
  $acc = $st2->fetch();
  if (!$acc) return null;

  return ['row'=>$row, 'account'=>['id'=>(int)$acc['id'],'username'=>$acc['username'],'email'=>(string)$acc['email']]];
}

/** Mark token used and cleanup */
function consume_token(PDO $sitePdo, int $tokenId, int $accountId): void {
  $sitePdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = :id")->execute([':id'=>$tokenId]);
  $sitePdo->prepare("DELETE FROM password_resets WHERE account_id = :aid AND (used_at IS NOT NULL OR expires_at < NOW())")
          ->execute([':aid'=>$accountId]);
}

/** Reset password using token: updates BOTH auth.account (SRP) and site accounts (hash) */
function reset_password_with_token(array $config, PDO $sitePdo, PDO $authPdo, string $token, string $newPassword): bool {
  $data = get_token_row($sitePdo, $authPdo, $token, 'password');
  if (!$data) return false;

  $accountId = (int)$data['account']['id'];
  $username  = $data['account']['username'];

  // Recompute SRP verifier for auth.account
  $salt = random_bytes(32);
  $ver  = srp6_compute_verifier($username, $newPassword, $salt);

  // Touch both DBs on same server
  $server = pdo_server($config['db']);
  if (!$server) throw new RuntimeException('DB server unavailable.');
  $siteDb = $config['db']['name'];
  $authDb = $config['auth_db']['name'];

  $server->beginTransaction();
  try {
    $stA = $server->prepare("UPDATE {$authDb}.account SET salt = :s, verifier = :v WHERE id = :id");
    $stA->bindParam(':s', $salt, PDO::PARAM_LOB);
    $stA->bindParam(':v', $ver,  PDO::PARAM_LOB);
    $stA->bindParam(':id', $accountId, PDO::PARAM_INT);
    $stA->execute();

    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $stW = $server->prepare("INSERT INTO {$siteDb}.accounts (id, username, email, password_hash, vp, total_dp, pre_register, created_at)
                             VALUES (:id, :u, :e, :h, 0, 0, 0, NOW())
                             ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)");
    $stW->execute([':id'=>$accountId, ':u'=>$username, ':e'=>$data['account']['email'], ':h'=>$hash]);

    $server->commit();
  } catch (Throwable $e) {
    $server->rollBack();
    throw $e;
  }

  consume_token($sitePdo, (int)$data['row']['id'], $accountId);
  return true;
}

/** Send reset link (by username or email). Always returns true (no user enumeration). */
function request_password_reset(array $config, PDO $sitePdo, PDO $authPdo, string $identifier): bool {
  $acc = find_account($sitePdo, $authPdo, $identifier);
  if (!$acc || empty($acc['email'])) return true;

  $token = create_reset_token($sitePdo, (int)$acc['id'], 'password', 60);
  $link  = absolute_url('reset?token=' . urlencode($token));
  $html  = '<p>Hello '.e($acc['username']).',</p><p>Use the link below to reset your password (valid for 60 minutes):</p>'.
           '<p><a href="'.e($link).'">'.e($link).'</a></p><p>If you did not request this, you can ignore it.</p>';
  mail_send($config, $acc['email'], 'Thorium WoW – Password reset', $html);
  return true;
}

/** Send username reminder(s) to an email. Always returns true. */
function request_username_reminder(array $config, PDO $authPdo, string $email): bool {
  $st = $authPdo->prepare("SELECT username FROM account WHERE email = :m LIMIT 10");
  $st->execute([':m' => $email]);
  $rows = $st->fetchAll();
  if (!$rows) return true;

  $list = '<ul style="margin:0;padding-left:16px">';
  foreach ($rows as $r) { $list .= '<li>'.e($r['username']).'</li>'; }
  $list .= '</ul>';

  $html = '<p>We received a request for usernames associated with this email.</p>'.$list.
          '<p>If you did not request this, you can ignore it.</p>';
  mail_send($config, $email, 'Thorium WoW – Your username(s)', $html);
  return true;
}

/** Fetch the site password_hash for verification. */
function site_get_password_hash(PDO $sitePdo, int $accountId): ?string {
  $st = $sitePdo->prepare("SELECT password_hash FROM accounts WHERE id = :id LIMIT 1");
  $st->execute([':id' => $accountId]);
  $row = $st->fetch();
  return $row ? (string)$row['password_hash'] : null;
}

/** Change password for a logged-in user (verifies current password, updates both DBs) */
function change_password_logged_in(array $config, PDO $sitePdo, PDO $authPdo, int $accountId, string $username, string $currentPassword, string $newPassword): void {
  $hash = site_get_password_hash($sitePdo, $accountId);
  if (!$hash || !password_verify($currentPassword, $hash)) {
    throw new RuntimeException('Your current password is incorrect.');
  }
  if (strlen($newPassword) < 6) throw new RuntimeException('New password must be at least 6 characters.');

  $salt = random_bytes(32);
  $ver  = srp6_compute_verifier($username, $newPassword, $salt);

  $server = pdo_server($config['db']);
  if (!$server) throw new RuntimeException('DB server unavailable.');
  $siteDb = $config['db']['name'];
  $authDb = $config['auth_db']['name'];

  $server->beginTransaction();
  try {
    $stA = $server->prepare("UPDATE {$authDb}.account SET salt = :s, verifier = :v WHERE id = :id");
    $stA->bindParam(':s', $salt, PDO::PARAM_LOB);
    $stA->bindParam(':v', $ver,  PDO::PARAM_LOB);
    $stA->bindParam(':id', $accountId, PDO::PARAM_INT);
    $stA->execute();

    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $stW = $server->prepare("UPDATE {$siteDb}.accounts SET password_hash = :h WHERE id = :id");
    $stW->execute([':h' => $newHash, ':id' => $accountId]);

    $server->commit();
  } catch (Throwable $e) {
    $server->rollBack();
    throw $e;
  }
}

// =============================================================================
// RBAC Admin utilities
// =============================================================================

/** Grant a permission to an account */
function auth_grant_permission(PDO $authPdo, int $accountId, int $permissionId, int $realmId = -1): void {
  $sql = "INSERT INTO rbac_account_permissions (accountId, permissionId, granted, realmId)
          VALUES (:aid, :pid, 1, :rid)
          ON DUPLICATE KEY UPDATE granted = 1";
  $st = $authPdo->prepare($sql);
  $st->execute([':aid' => $accountId, ':pid' => $permissionId, ':rid' => $realmId]);
}

/** Revoke a permission from an account */
function auth_revoke_permission(PDO $authPdo, int $accountId, int $permissionId, int $realmId = -1): void {
  $sql = "UPDATE rbac_account_permissions SET granted = 0 WHERE accountId = :aid AND permissionId = :pid AND realmId = :rid";
  $st = $authPdo->prepare($sql);
  $st->execute([':aid' => $accountId, ':pid' => $permissionId, ':rid' => $realmId]);
}

/** Get all permissions for an account */
function auth_get_account_permissions(PDO $authPdo, int $accountId): array {
  $sql = "SELECT permissionId FROM rbac_account_permissions WHERE accountId = :aid AND granted = 1";
  $st = $authPdo->prepare($sql);
  $st->execute([':aid' => $accountId]);
  return array_column($st->fetchAll(), 'permissionId');
}

/** Get all admin accounts (for audits) */
function auth_get_all_admin_accounts(PDO $authPdo): array {
  $sql = "SELECT a.id, a.username, MAX(rap.permissionId) as highest_permission
          FROM account a
          JOIN rbac_account_permissions rap ON a.id = rap.accountId
          WHERE rap.granted = 1 AND rap.permissionId BETWEEN 191 AND 198
          GROUP BY a.id, a.username
          ORDER BY MAX(rap.permissionId) DESC, a.username";
  $st = $authPdo->prepare($sql);
  $st->execute();
  return $st->fetchAll();
}

// =============================================================================
// Nickname Support
// =============================================================================

/** Get user's current display name from session */
function auth_user_display_name(): string {
  $user = auth_user();
  if (!$user) return 'Guest';
  return $user['nickname'] ?? $user['username'] ?? ('User ' . $user['id']);
}

/** Refresh user session with updated nickname */
function auth_refresh_user_nickname(PDO $pdo): void {
  $user = auth_user();
  if (!$user) return;
  $nickname_info = get_user_nickname_info($pdo, (int)$user['id']);
  $_SESSION['user']['nickname'] = $nickname_info['nickname'] ?: $_SESSION['user']['username'];
}

/** Hook after successful login to ensure nickname exists */
function post_login_hook(PDO $pdo, int $user_id): void {
  $nickname = ensure_user_nickname($pdo, $user_id);
  if (isset($_SESSION['user']) && $_SESSION['user']['id'] == $user_id) {
    $_SESSION['user']['nickname'] = $nickname;
  }
  error_log("User {$user_id} ensured nickname: {$nickname}");
}
