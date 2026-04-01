<?php
/**
 * Bloodmarks Repository
 * 
 * Repository for managing Bloodmarking PvP system leaderboards and statistics.
 * Automatically excludes GM/staff accounts using RBAC permissions and legacy
 * gmlevel systems. Provides top player rankings with character information.
 */
declare(strict_types=1);

/**
 * Get cached characters database connection
 * 
 * @return PDO Characters database connection
 * @throws Exception When database connection fails
 */
function pdo_chars(): PDO {
  static $pdo = null;
  if ($pdo instanceof PDO) return $pdo;

  if (!isset($GLOBALS['config']) || !is_array($GLOBALS['config'])) {
    $cfgFile = __DIR__ . '/config.php';
    $GLOBALS['config'] = is_file($cfgFile) ? require $cfgFile : [];
  }
  $cfg = $GLOBALS['config'];
  $db  = $cfg['characters_db'] ?? [
    'host'=>'127.0.0.1','port'=>3306,'name'=>'characters','user'=>'root','pass'=>'','charset'=>'utf8mb4',
  ];

  $dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    (string)($db['host'] ?? '127.0.0.1'),
    (int)   ($db['port'] ?? 3306),
    (string)($db['name'] ?? 'characters'),
    (string)($db['charset'] ?? 'utf8mb4')
  );

  $pdo = new PDO($dsn, (string)$db['user'], (string)$db['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  return $pdo;
}

/**
 * Get cached auth database connection (optional)
 * 
 * @return PDO|null Auth database connection or null if unavailable
 */
function pdo_auth(): ?PDO {
  static $pdo = null;
  if ($pdo instanceof PDO) return $pdo;

  if (!isset($GLOBALS['config']) || !is_array($GLOBALS['config'])) {
    $cfgFile = __DIR__ . '/config.php';
    $GLOBALS['config'] = is_file($cfgFile) ? require $cfgFile : [];
  }
  $cfg = $GLOBALS['config'];
  $db  = $cfg['auth_db'] ?? null;
  if (!$db) return null;

  try {
    $dsn = sprintf(
      'mysql:host=%s;port=%d;dbname=%s;charset=%s',
      (string)($db['host'] ?? '127.0.0.1'),
      (int)   ($db['port'] ?? 3306),
      (string)($db['name'] ?? 'auth'),
      (string)($db['charset'] ?? 'utf8mb4')
    );
    $pdo = new PDO($dsn, (string)$db['user'], (string)$db['pass'], [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
  } catch (Throwable $e) {
    error_log('bloodmarks_repo: auth DB connect failed: ' . $e->getMessage());
    return null; // fail open
  }
}

/**
 * Get account IDs to exclude from leaderboards (staff/admin accounts)
 * 
 * Collects accounts that should be excluded from public leaderboards based on:
 * - RBAC permissions (rbac_account_permissions.permissionId >= threshold)
 * - Legacy gmlevel system (account_access.gmlevel >= threshold)
 * 
 * @param int $permThreshold Minimum permission level to exclude
 * @return array Account IDs to exclude from leaderboards
 */
function bloodmarks_excluded_accounts(int $permThreshold): array {
  $pdoAuth = pdo_auth();
  if (!$pdoAuth) return [];

  $ids = [];

  // Try RBAC table (AzerothCore/Trinity RBAC)
  try {
    $stmt = $pdoAuth->prepare("
      SELECT DISTINCT accountId AS id
      FROM rbac_account_permissions
      WHERE permissionId >= :perm
    ");
    $stmt->execute([':perm' => $permThreshold]);
    $ids = array_merge($ids, array_map('intval', array_column($stmt->fetchAll(), 'id')));
  } catch (Throwable $e) {
    // RBAC table may not exist — ignore
  }

  // Try legacy account_access (gmlevel per account, RealmID ignored => any realm counts)
  try {
    $stmt = $pdoAuth->prepare("
      SELECT DISTINCT id
      FROM account_access
      WHERE gmlevel >= :perm
    ");
    $stmt->execute([':perm' => $permThreshold]);
    $ids = array_merge($ids, array_map('intval', array_column($stmt->fetchAll(), 'id')));
  } catch (Throwable $e) {
    // legacy table may not exist — ignore
  }

  // Deduplicate
  $ids = array_values(array_unique(array_filter($ids, fn($v) => is_int($v) && $v > 0)));
  return $ids;
}

/**
 * Get top bloodmarking players with character details
 * 
 * Fetches leaderboard data excluding staff/admin accounts. Results are sorted by:
 * 1. Bloodmarking level (DESC)
 * 2. Total marks (DESC) 
 * 3. Total victims (DESC)
 * 4. Character name (ASC)
 * 
 * @param int $limit Maximum number of results to return
 * @param string $table Bloodmarks table name
 * @return array Top bloodmarking players with character information
 */
function bloodmarks_top(int $limit = 10, string $table = 'character_bloodmarks'): array {
  $pdoChars       = pdo_chars();
  $permThreshold  = (int)($GLOBALS['config']['admin_min_permission_id'] ?? 191);
  $excludeIds     = bloodmarks_excluded_accounts($permThreshold);

  // Build NOT IN (:acc0, :acc1, ...) if we have excludes
  $params = [ ':lim' => $limit ];
  $whereExclude = '';
  if (!empty($excludeIds)) {
    $phs = [];
    foreach ($excludeIds as $i => $id) {
      $k = ':acc' . $i;
      $phs[] = $k;
      $params[$k] = $id;
    }
    // characters.account holds owning account id
    $whereExclude = ' AND c.account NOT IN (' . implode(',', $phs) . ')';
  }

  $sql = "SELECT bm.guid,
                 CAST(bm.level   AS UNSIGNED) AS bm_level,
                 CAST(bm.marks   AS UNSIGNED) AS marks,
                 CAST(bm.victims AS UNSIGNED) AS victims,
                 c.name,
                 c.race,
                 c.class,
                 c.gender,
                 c.level AS char_level
          FROM `$table` bm
          JOIN `characters` c ON c.guid = bm.guid
          WHERE 1=1 {$whereExclude}
          ORDER BY bm_level DESC, marks DESC, victims DESC, c.name ASC
          LIMIT :lim";

  try {
    $stmt = $pdoChars->prepare($sql);
    foreach ($params as $k => $v) {
      $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    return $stmt->fetchAll() ?: [];
  } catch (Throwable $e) {
    error_log('bloodmarks_top error: ' . $e->getMessage());
    return [];
  }
}
