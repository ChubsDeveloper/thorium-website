<?php
/**
 * app/realms_repo.php
 * Data repository - manages realms data operations
 */
declare(strict_types=1);

// app/realms_repo.php
declare(strict_types=1);

/**
 * AUTH DB (realmlist + uptime)
 */
    /** Handle realms_pdo_auth operation. */
function realms_pdo_auth(): PDO {
  static $pdo = null;
  if ($pdo instanceof PDO) return $pdo;

  if (!isset($GLOBALS['config']) || !is_array($GLOBALS['config'])) {
    $cfg = __DIR__ . '/config.php';
    $GLOBALS['config'] = is_file($cfg) ? require $cfg : [];
  }
  $db = $GLOBALS['config']['auth_db'] ?? [
    'host' => '127.0.0.1', 'name' => 'auth', 'user' => 'root', 'pass' => '',
    'charset' => 'utf8mb4'
  ];

  $dsn = "mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}";
  $pdo = new PDO($dsn, $db['user'], $db['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  return $pdo;
}

/**
 * Load all realms from auth.realmlist
 * id, name, address, port
 */
    /** Process and return array data. */
function realms_all(): array {
  $pdo = realms_pdo_auth();
  try {
    $stmt = $pdo->query("SELECT id, name, address, port, population FROM realmlist");
    return $stmt->fetchAll() ?: [];
  } catch (Throwable $e) {
    $stmt = $pdo->query("SELECT id, name, address, port FROM realmlist");
    $rows = $stmt->fetchAll() ?: [];
    foreach ($rows as &$r) $r['population'] = null;
    return $rows;
  }
}

/**
 * Filter realms for visibility (hide staff-only from non-admins)
 */
    /** Process and return array data. */
function realms_all_visible(bool $isAdmin): array {
  $all = realms_all();
  $adminOnly = array_map('intval', $GLOBALS['config']['realms']['admin_only_ids'] ?? []);
  if ($isAdmin || !$adminOnly) return $all;

  return array_values(array_filter($all, static function ($r) use ($adminOnly) {
    $id = (int)($r['id'] ?? 0);
    return $id > 0 && !in_array($id, $adminOnly, true);
  }));
}

/**
 * Quick TCP probe for Online/Offline + latency
 */
    /** Process and return array data. */
function realm_probe(string $host, int $port, float $timeoutSec = 0.6): array {
  $start = microtime(true);
  $errno = 0; $err = '';
  $sock = @fsockopen($host, $port, $errno, $err, $timeoutSec);
  if ($sock) {
    fclose($sock);
    $ms = (int) round((microtime(true) - $start) * 1000);
    return ['online' => true, 'latency_ms' => max(1, $ms)];
  }
  return ['online' => false, 'latency_ms' => null];
}

/**
 * CHARACTERS DB connection for a given realm.
 *
 * Config options (in app/config.php):
 *   1) Same DB for all realms: 'characters_db' (or legacy 'char_db')
 *   2) Per-realm DB map: 'realm_dbs' => [realmId => [host,name,user,pass,charset]]
 *
 * If neither provided, we try: characters, characters{$id}, characters_{$id}
 * using auth_db credentials.
 */
    /** Handle realm_char_pdo operation. */
function realm_char_pdo(int $realmId): ?PDO {
  static $cache = [];
  if (isset($cache[$realmId])) return $cache[$realmId];

  // Ensure config is loaded
  if (!isset($GLOBALS['config']) || !is_array($GLOBALS['config'])) {
    $cfg = __DIR__ . '/config.php';
    $GLOBALS['config'] = is_file($cfg) ? require $cfg : [];
  }

  // Auth creds for fallbacks
  $auth = $GLOBALS['config']['auth_db'] ?? [
    'host'=>'127.0.0.1','user'=>'root','pass'=>'','charset'=>'utf8mb4'
  ];

  // 1) Explicit per-realm DB mapping (recommended for staff/test realms)
  if (!empty($GLOBALS['config']['realm_dbs'][$realmId])) {
    $db = $GLOBALS['config']['realm_dbs'][$realmId];
    $dsn = "mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}";
    try {
      return $cache[$realmId] = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      ]);
    } catch (Throwable $e) { return $cache[$realmId] = null; }
  }

  // 2) Single characters DB for ALL realms
  //    Support both 'characters_db' and 'char_db' keys.
  if (!empty($GLOBALS['config']['characters_db']) || !empty($GLOBALS['config']['char_db'])) {
    $db = $GLOBALS['config']['characters_db'] ?? $GLOBALS['config']['char_db'];
    $dsn = "mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}";
    try {
      return $cache[$realmId] = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      ]);
    } catch (Throwable $e) { return $cache[$realmId] = null; }
  }

  // 3) Fallback names — try realm-specific **first**, then generic
  //    This fixes the “both realms use characters” issue.
  $candidates = ["characters{$realmId}", "characters_{$realmId}", "characters"];
  foreach ($candidates as $name) {
    $dsn = "mysql:host={$auth['host']};dbname={$name};charset={$auth['charset']}";
    try {
      return $cache[$realmId] = new PDO($dsn, $auth['user'], $auth['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      ]);
    } catch (Throwable $e) {
      // try next candidate
    }
  }
  return $cache[$realmId] = null;
}

/**
 * Online players for a realm (characters.online = 1)
 */
    /** Calculate and return numeric value. */
function realm_online_count(int $realmId): ?int {
  $pdo = realm_char_pdo($realmId);
  if (!$pdo) return null;
  try {
    $n = (int)$pdo->query("SELECT COUNT(*) FROM characters WHERE online = 1")->fetchColumn();
    return $n;
  } catch (Throwable $e) {
    return null;
  }
}

/**
 * Latest uptime (seconds) from auth.uptime for given realm
 * Table columns typically: realmid, starttime, uptime, maxplayers, revision
 */
    /** Handle realm_uptime_seconds operation. */
function realm_uptime_seconds(int $realmId): ?int {
  $pdo = realms_pdo_auth();
  try {
    $stmt = $pdo->prepare("SELECT uptime FROM uptime WHERE realmid = ? ORDER BY starttime DESC LIMIT 1");
    $stmt->execute([$realmId]);
    $val = $stmt->fetchColumn();
    return $val !== false ? (int)$val : null;
  } catch (Throwable $e) {
    return null;
  }
}

/**
 * Nicely format seconds to 1d 2h 3m (max two units)
 */
    /** Process and format data. */
function format_uptime_short(?int $secs): string {
  if ($secs === null) return '—';
  $s = max(0, $secs);
  $d = intdiv($s, 86400); $s %= 86400;
  $h = intdiv($s, 3600);  $s %= 3600;
  $m = intdiv($s, 60);
  $parts = [];
  if ($d > 0) $parts[] = $d . 'd';
  if ($h > 0) $parts[] = $h . 'h';
  if ($d === 0 && $m > 0) $parts[] = $m . 'm';
  if (!$parts) $parts[] = '0m';
  return implode(' ', array_slice($parts, 0, 2));
}

/** Optional: turn 0..1 to label if you still want a density tag somewhere */
    /** Process and return string data. */
function realm_pop_label($p): string {
  if ($p === null) return '—';
  $p = (float)$p;
  if ($p >= 0.80) return 'HIGH';
  if ($p >= 0.30) return 'MED';
  return 'LOW';
}
