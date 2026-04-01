<?php
/**
 * app/honorable_kills_repo.php
 * Honorable Kills data repo — correct DB key, port support, numeric ordering.
 */
declare(strict_types=1);

/** Get (and cache) a PDO connection to the characters DB. */
function honorable_kills_pdo_chars(): PDO {
  static $pdo = null;
  if ($pdo instanceof PDO) return $pdo;

  // Ensure config is available
  if (!isset($GLOBALS['config']) || !is_array($GLOBALS['config'])) {
    $cfgFile = __DIR__ . '/config.php';
    $GLOBALS['config'] = is_file($cfgFile) ? require $cfgFile : [];
  }
  $cfg = $GLOBALS['config'];

  // ✅ Use the correct key from config: 'characters_db'
  $db = $cfg['characters_db'] ?? [
    'host'    => '127.0.0.1',
    'port'    => 3306,
    'name'    => 'characters',
    'user'    => 'root',
    'pass'    => '',
    'charset' => 'utf8mb4',
  ];

  $host    = (string)($db['host'] ?? '127.0.0.1');
  $port    = (int)   ($db['port'] ?? 3306);
  $name    = (string)($db['name'] ?? 'characters');
  $user    = (string)($db['user'] ?? 'root');
  $pass    = (string)($db['pass'] ?? '');
  $charset = (string)($db['charset'] ?? 'utf8mb4');

  $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
  $pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  return $pdo;
}

/**
 * Fetch top Honorable Kills rows.
 * Primary sort: total kills desc; tiebreakers: honor points desc, char level desc, name asc.
 * CASTs to UNSIGNED to avoid lexicographic ordering when columns are VARCHAR.
 *
 * @param int    $limit  how many to return
 * @param string $table  table name in characters DB, default 'character_honor'
 * @return array<int, array<string,mixed>>
 */
function honorable_kills_top(int $limit = 10, string $table = 'character_honor'): array {
  $pdo = honorable_kills_pdo_chars();

  // Try character_honor table first (commonly used on some cores)
  try {
    $sql = "SELECT hk.guid,
                   CAST(hk.totalKills        AS UNSIGNED) AS total_kills,
                   CAST(hk.totalHonorPoints  AS UNSIGNED) AS honor_points,
                   CAST(hk.todayHK           AS UNSIGNED) AS today_kills,
                   CAST(hk.yesterdayHK       AS UNSIGNED) AS yesterday_kills,
                   c.name,
                   c.race,
                   c.class,
                   c.gender,
                   CAST(c.level AS UNSIGNED) AS char_level
            FROM `$table` hk
            JOIN `characters` c ON c.guid = hk.guid
            ORDER BY total_kills DESC, honor_points DESC, char_level DESC, c.name ASC
            LIMIT :lim";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    if ($rows) return $rows;
  } catch (Throwable $e) {
    // fall through to characters fallback
  }

  // Fallback: some cores store HKs directly on characters table
  try {
    $sql = "SELECT c.guid,
                   CAST(c.totalKills        AS UNSIGNED) AS total_kills,
                   CAST(c.totalHonorPoints  AS UNSIGNED) AS honor_points,
                   CAST(c.todayKills        AS UNSIGNED) AS today_kills,
                   CAST(c.yesterdayKills    AS UNSIGNED) AS yesterday_kills,
                   c.name,
                   c.race,
                   c.class,
                   c.gender,
                   CAST(c.level AS UNSIGNED) AS char_level
            FROM `characters` c
            WHERE c.totalKills > 0
            ORDER BY total_kills DESC, honor_points DESC, char_level DESC, c.name ASC
            LIMIT :lim";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll() ?: [];
  } catch (Throwable $e2) {
    return [];
  }
}
