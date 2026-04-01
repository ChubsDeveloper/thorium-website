<?php
/**
 * app/characters_repo.php
 * Data repository - manages characters data operations
 */

// app/characters_repo.php
// Uses config['char_db'] (your config) OR config['characters_db'] (fallback)

    /** Handle characters_pdo operation. */
function characters_pdo(): ?PDO {
  static $pdo = null;
  if ($pdo instanceof PDO) return $pdo;

  $cfg = $GLOBALS['config'] ?? [];

  $cdb = $cfg['char_db'] ?? $cfg['characters_db'] ?? null;
  if (!$cdb) {
    // fallback: use auth creds but db "characters"
    $cdb = [
      'host'    => $cfg['auth_db']['host'] ?? '127.0.0.1',
      'name'    => 'characters',
      'user'    => $cfg['auth_db']['user'] ?? 'root',
      'pass'    => $cfg['auth_db']['pass'] ?? 'root',
      'charset' => 'utf8mb4',
    ];
  } else {
    $cdb = array_merge([
      'host'    => '127.0.0.1',
      'name'    => 'characters',
      'user'    => 'root',
      'pass'    => 'root',
      'charset' => 'utf8mb4',
    ], $cdb);
  }

  $dsn = "mysql:host={$cdb['host']};dbname={$cdb['name']};charset={$cdb['charset']}";
  try {
    $pdo = new PDO($dsn, $cdb['user'], $cdb['pass'], [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
  } catch (Throwable $e) {
    return null;
  }
}

/** List characters for an account (Trinity/AzerothCore). */
    /** Process and return array data. */
function characters_for_account(int $accountId, int $limit = 100): array {
  $pdo = characters_pdo();
  if (!$pdo) return [];
  $sql = "SELECT guid, name, race, class, gender, level, online, map, zone
          FROM characters
          WHERE account = :acc
          ORDER BY level DESC, name ASC
          LIMIT :lim";
  $st = $pdo->prepare($sql);
  $st->bindValue(':acc', $accountId, PDO::PARAM_INT);
  $st->bindValue(':lim', (int)$limit, PDO::PARAM_INT);
  $st->execute();
  return $st->fetchAll();
}

/** Get a character row by guid (for ownership/online checks). */
    /** Handle character_by_guid operation. */
function character_by_guid(int $guid): ?array {
  $pdo = characters_pdo();
  if (!$pdo) return null;
  $st = $pdo->prepare("SELECT guid, account, online FROM characters WHERE guid = :g");
  $st->execute([':g'=>$guid]);
  $r = $st->fetch();
  return $r ?: null;
}

/** Get homebind coords for a guid, trying multiple schema variants. */
    /** Handle character_homebind_coords operation. */
function character_homebind_coords(int $guid): ?array {
  $pdo = characters_pdo();
  if (!$pdo) return null;

  // Try common schemas. We just select * and map keys defensively.
  $st = $pdo->prepare("SELECT * FROM character_homebind WHERE guid = :g LIMIT 1");
  $st->execute([':g' => $guid]);
  $hb = $st->fetch();
  if (!$hb) return null;

  // Map possible column names -> normalized
  $map = $hb['map']    ?? $hb['mapId']  ?? $hb['Map']  ?? null;
  $zone= $hb['zone']   ?? $hb['zoneId'] ?? $hb['Zone'] ?? null;
  $x   = $hb['position_x'] ?? $hb['posX'] ?? $hb['x'] ?? $hb['X'] ?? null;
  $y   = $hb['position_y'] ?? $hb['posY'] ?? $hb['y'] ?? $hb['Y'] ?? null;
  $z   = $hb['position_z'] ?? $hb['posZ'] ?? $hb['z'] ?? $hb['Z'] ?? null;

  if ($map === null || $x === null || $y === null || $z === null) return null;

  return [
    'map'  => (int)$map,
    'zone' => ($zone === null ? null : (int)$zone),
    'x'    => (float)$x,
    'y'    => (float)$y,
    'z'    => (float)$z,
  ];
}

/**
 * Offline unstuck to homebind: moves character to hearth/homebind.
 * - Verifies ownership (account id)
 * - Requires offline (online=0)
 * - Updates characters.position_x/y/z, map, zone (orientation reset to 0)
 */
    /** Process and return array data. */
function characters_unstuck(int $accountId, int $guid): array {
  $pdo = characters_pdo();
  if (!$pdo) return [false, 'Characters DB unavailable.'];

  $ch = character_by_guid($guid);
  if (!$ch)          return [false, 'Character not found.'];
  if ((int)$ch['account'] !== $accountId) return [false, 'That character is not on your account.'];
  if ((int)$ch['online']  !== 0)          return [false, 'Please log out the character first.'];

  $hb = character_homebind_coords($guid);
  if (!$hb) return [false, 'This character has no home bind set. Please set your hearthstone in-game once.'];

  try {
    $pdo->beginTransaction();
    $sql = "UPDATE characters
            SET position_x = :x, position_y = :y, position_z = :z,
                map = :map, zone = :zone, orientation = 0
            WHERE guid = :g AND online = 0";
    $st = $pdo->prepare($sql);
    $st->execute([
      ':x'   => $hb['x'],
      ':y'   => $hb['y'],
      ':z'   => $hb['z'],
      ':map' => $hb['map'],
      ':zone'=> $hb['zone'] ?? 0,
      ':g'   => $guid,
    ]);
    $pdo->commit();
    return [true, 'Character moved to home bind.'];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    return [false, 'Failed to unstuck: '.$e->getMessage()];
  }
}
