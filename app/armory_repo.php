<?php
/**
 * app/armory_repo.php
 * Complete armory repository with enchants, gems, and set bonuses
 * ✅ OPTION 2: Shows enchant effects for gems (no gemproperties table needed)
 */
declare(strict_types=1);

/* 
   Config helpers + PDOs
 */

// Load config if not already loaded
if (!isset($GLOBALS['config']) || !is_array($GLOBALS['config'])) {
    $cfgFile = __DIR__ . '/config.php';
    if (is_file($cfgFile)) {
        $GLOBALS['config'] = require $cfgFile;
    } else {
        $GLOBALS['config'] = [];
    }
}

function armory_guess_world_name(string $charsName): string {
    return (stripos($charsName, 'characters') !== false)
        ? preg_replace('/characters/i', 'world', $charsName)
        : 'world';
}

/** Characters DB */
function armory_pdo_chars(): PDO {
    static $pdo = null; 
    if ($pdo instanceof PDO) return $pdo;
    
    $config = $GLOBALS['config'];
    $db = $config['characters_db'] ?? [
        'host'    => $config['auth_db']['host'] ?? '127.0.0.1',
        'port'    => $config['auth_db']['port'] ?? 3306,
        'name'    => 'characters',
        'user'    => $config['auth_db']['user'] ?? 'root',
        'pass'    => $config['auth_db']['pass'] ?? '',
        'charset' => $config['auth_db']['charset'] ?? 'utf8mb4',
    ];
    
    $port = isset($db['port']) ? ";port={$db['port']}" : '';
    $dsn = "mysql:host={$db['host']}{$port};dbname={$db['name']};charset={$db['charset']}";
    
    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    return $pdo;
}

/** World DB */
function armory_pdo_world(): PDO {
    static $pdo = null; 
    if ($pdo instanceof PDO) return $pdo;
    
    $config = $GLOBALS['config'];
    $charDb = $config['characters_db'] ?? $config['char_db'] ?? null;
    $fallbackName = $charDb ? armory_guess_world_name($charDb['name'] ?? 'characters') : 'world';

    $db = $config['world_db'] ?? [
        'host'    => $charDb['host'] ?? '127.0.0.1',
        'port'    => $charDb['port'] ?? 3306,
        'name'    => $fallbackName,
        'user'    => $charDb['user'] ?? 'root',
        'pass'    => $charDb['pass'] ?? '',
        'charset' => $charDb['charset'] ?? 'utf8mb4',
    ];

    $port = isset($db['port']) ? ";port={$db['port']}" : '';
    $dsn = "mysql:host={$db['host']}{$port};dbname={$db['name']};charset={$db['charset']}";
    
    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    return $pdo;
}

/** Auth DB */
function armory_pdo_auth(): PDO {
    static $pdo = null; 
    if ($pdo instanceof PDO) return $pdo;
    
    $config = $GLOBALS['config'];
    $db = $config['auth_db'] ?? [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'auth',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ];
    
    $port = isset($db['port']) ? ";port={$db['port']}" : '';
    $dsn = "mysql:host={$db['host']}{$port};dbname={$db['name']};charset={$db['charset']}";
    
    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    return $pdo;
}

/* 
   Table introspection helpers
 */

function armory_table_exists(PDO $pdo, string $table): bool {
    try {
        $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES 
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $st->execute([$table]); 
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        error_log("armory_table_exists error for {$table}: " . $e->getMessage());
        return false;
    }
}

function armory_table_columns_map(PDO $pdo, string $table): array {
    try {
        $st = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $st->execute([$table]);
        $out = []; 
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) { 
            $out[strtolower($c)] = $c; 
        }
        return $out;
    } catch (Throwable $e) {
        error_log("armory_table_columns_map error for {$table}: " . $e->getMessage());
        return [];
    }
}

/* 
   Basic data fetching
 */

function armory_fetch_realms(PDO $pdoAuth): array {
    try {
        $st = $pdoAuth->query("SELECT id, name FROM realmlist ORDER BY id");
        $realms = [];
        foreach ($st as $r) {
            $realms[(int)$r['id']] = (string)$r['name'];
        }
        return $realms;
    } catch (Throwable $e) {
        error_log("armory_fetch_realms error: " . $e->getMessage());
        return [1 => 'Default Realm'];
    }
}

function armory_fetch_character(PDO $pdoChars, string $name): ?array {
    try {
$sql = "SELECT guid, name, level, class, race, gender, equipmentCache,
               totalHonorPoints, totalKills, arenaPoints,
               skin, face, hairStyle, hairColor, facialStyle,
               activeTalentGroup
        FROM characters 
        WHERE LOWER(name) = LOWER(:name) 
        LIMIT 1";
        $st = $pdoChars->prepare($sql);
        $st->execute([':name' => $name]);
        $result = $st->fetch();
        return $result ?: null;
    } catch (Throwable $e) {
        error_log("armory_fetch_character error for {$name}: " . $e->getMessage());
        return null;
    }
}

function armory_fetch_character_by_guid(PDO $pdoChars, int $guid): ?array {
    try {
        $sql = "SELECT guid, name, level, class, race, gender, equipmentCache,
                       totalHonorPoints, totalKills, arenaPoints,
                       skin, face, hairStyle, hairColor, facialStyle,
                       activeTalentGroup
                FROM characters
                WHERE guid = :guid
                LIMIT 1";
        $st = $pdoChars->prepare($sql);
        $st->execute([':guid' => $guid]);
        $result = $st->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    } catch (Throwable $e) {
        error_log("armory_fetch_character_by_guid error for {$guid}: " . $e->getMessage());
        return null;
    }
}

function armory_build_talent_payload(PDO $pdoChars, PDO $pdoWorld, int $guid, int $classId, int $group): array
{
    // 1) Picked talent spells for this talentGroup
    $st = $pdoChars->prepare("SELECT spell FROM character_talent WHERE guid = ? AND talentGroup = ?");
    $st->execute([$guid, $group]);
    $picked = $st->fetchAll(PDO::FETCH_COLUMN, 0);

    $pickedSet = [];
    foreach ($picked as $sid) $pickedSet[(int)$sid] = true;

    // 2) talenttab.classMask in your DB is already the WotLK class mask (Paladin=2, Mage=128, Druid=1024, etc)
    // So compute the mask from classId:
    // classId mapping: 1=Warrior,2=Paladin,3=Hunter,4=Rogue,5=Priest,6=DK,7=Shaman,8=Mage,9=Warlock,11=Druid
    $classToMask = [
        1 => 1,
        2 => 2,
        3 => 4,
        4 => 8,
        5 => 16,
        6 => 32,
        7 => 64,
        8 => 128,
        9 => 256,
        11 => 1024,
    ];
    $classMask = (int)($classToMask[$classId] ?? 0);

    // 3) Fetch tabs for this class
    $st = $pdoWorld->prepare("
        SELECT
            id,
            Name1, Name2, Name3, Name4, Name5, Name6, Name7, Name8,
            Name9, Name10, Name11, Name12, Name13, Name14, Name15, Name16,
            iconId,
            classMask,
            orderIndex,
            backgroundFileName
        FROM talenttab
        WHERE (classMask & ?) != 0
        ORDER BY orderIndex ASC, id ASC
    ");
    $st->execute([$classMask]);
    $tabsRaw = $st->fetchAll(PDO::FETCH_ASSOC);

    $tabs = [];
    foreach ($tabsRaw as $r) {
        $name = '';
        for ($i=1; $i<=16; $i++) {
            $k = "Name{$i}";
            $v = trim((string)($r[$k] ?? ''));
            if ($v !== '') { $name = $v; break; }
        }
        if ($name === '') $name = 'Tree';

        $tabs[] = [
            'id'    => (int)$r['id'],
            'name'  => $name,
            'iconId'=> (int)($r['iconId'] ?? 0),
            'bg'    => (string)($r['backgroundFileName'] ?? ''),
            'order' => (int)($r['orderIndex'] ?? 0),
            'classMask' => (int)($r['classMask'] ?? 0),
        ];
    }

    $tabIds = array_map(fn($t) => (int)$t['id'], $tabs);
    if (!$tabIds) {
        return [
            'guid'=>$guid,'classId'=>$classId,'group'=>$group,
            'tabs'=>[],'talents'=>[],
            'learnedRanks'=>[],'spentByTab'=>[],
            'pickedSpellCount'=>count($pickedSet),
        ];
    }

    // 4) Fetch talents for those tabs (your schema)
    $in = implode(',', array_fill(0, count($tabIds), '?'));
    $st = $pdoWorld->prepare("
        SELECT
            id,
            talentTabId,
            tierId,
            columnIndex,
            spellRank1, spellRank2, spellRank3, spellRank4, spellRank5,
            spellRank6, spellRank7, spellRank8, spellRank9,
            prereqTalent1, prereqTalent2, prereqTalent3,
            prereqRank1, prereqRank2, prereqRank3
        FROM talent
        WHERE talentTabId IN ($in)
        ORDER BY talentTabId ASC, tierId ASC, columnIndex ASC, id ASC
    ");
    $st->execute($tabIds);
    $talentsRaw = $st->fetchAll(PDO::FETCH_ASSOC);

    // 5) Compute learnedRanks + shape output like your JS expects
    $talents = [];
    $learnedRanks = []; // talentId => rank

    foreach ($talentsRaw as $t) {
        $tid = (int)$t['id'];

        $rankSpells = [];
        $maxRank = 0;

        for ($i=1; $i<=9; $i++) {
            $sid = (int)($t["spellRank{$i}"] ?? 0);
            if ($sid > 0) {
                $rankSpells[] = $sid;
                $maxRank = $i; // last non-zero rank
                if (isset($pickedSet[$sid])) {
                    // if player has this rank spell, they have at least rank i
                    $learnedRanks[$tid] = max((int)($learnedRanks[$tid] ?? 0), $i);
                }
            }
        }

        $p1 = (int)($t['prereqTalent1'] ?? 0);
        $r1 = (int)($t['prereqRank1'] ?? 0);

        $talents[] = [
            'id' => $tid,
            'tabId' => (int)$t['talentTabId'],
            'row' => (int)$t['tierId'],
            'col' => (int)$t['columnIndex'],
            'rankSpells' => $rankSpells,
            'maxRank' => max(1, $maxRank),
            'prereq' => ($p1 > 0 ? ['talentId'=>$p1, 'rank'=>$r1] : null),
            // icon/iconUrl can be filled by your API step (spell->SpellIconID->spellicon)
            'icon' => '',
            'iconUrl' => '',
        ];
    }

    // 6) Spent points per tab (sum of learned ranks)
    $spentByTab = array_fill_keys($tabIds, 0);
    foreach ($learnedRanks as $talentId => $rank) {
        // find the tabId for this talent
        // (cheap loop; you can optimize with a map)
        foreach ($talents as $tt) {
            if ((int)$tt['id'] === (int)$talentId) {
                $tabId = (int)$tt['tabId'];
                if (isset($spentByTab[$tabId])) $spentByTab[$tabId] += (int)$rank;
                break;
            }
        }
    }

    return [
        'guid' => $guid,
        'classId' => $classId,
        'group' => $group,
        'tabs' => $tabs,
        'talents' => $talents,
        'learnedRanks' => $learnedRanks,
        'spentByTab' => $spentByTab,
        'pickedSpellCount' => count($pickedSet),
    ];
}

/* 
   Equipment processing
 */

function armory_parse_equipment_cache(?string $cache): array {
    if (!$cache || trim($cache) === '') {
        return [];
    }
    
    $cache = preg_replace('/\s+/', ' ', trim($cache));
    
    // Check if it contains delimiters (colon, semicolon format)
    if (preg_match('/[:;,\|]/', $cache)) {
        $groups = preg_split('/[; ]+/', $cache, -1, PREG_SPLIT_NO_EMPTY);
        $out = [];
        foreach ($groups as $i => $g) {
            $parts = preg_split('/[:|,]/', $g);
            $out[$i] = (int)($parts[0] ?? 0);
        }
        return $out;
    }
    
    // Space-separated numbers
    $nums = array_values(array_filter(
        array_map('intval', preg_split('/\s+/', $cache, -1, PREG_SPLIT_NO_EMPTY)),
        fn($n) => $n >= 0
    ));
    
    if (!$nums) return [];
    $out = [];
    
    // Check if it's the 5-field format (entry displayid 0 0 0)
    if (count($nums) % 5 === 0 && count($nums) >= 5 * 19) {
        for ($i = 0, $s = 0; $i < 5 * 19; $i += 5, $s++) {
            $out[$s] = $nums[$i];
        }
    } else {
        // Simple entry-per-slot format
        for ($s = 0; $s < min(19, count($nums)); $s++) {
            $out[$s] = $nums[$s];
        }
    }
    
    return $out;
}

function armory_detect_inventory_schema(PDO $pdo): ?array {
    if (!armory_table_exists($pdo, 'character_inventory')) return null;

    $cols = armory_table_columns_map($pdo, 'character_inventory');

    $slotCol = $cols['slot'] ?? null;
    $guidCol = $cols['guid'] ?? null;
    $itemCol = $cols['item'] ?? ($cols['item_guid'] ?? null);

    // bag is optional in your schema
    $bagCol  = $cols['bag'] ?? ($cols['bag_id'] ?? null);

    // enchantments is optional but we want it if present
    $enchCol = $cols['enchantments'] ?? null;

    if (!$slotCol || !$guidCol || !$itemCol) return null;

    return [
        'table' => 'character_inventory',
        'slot'  => $slotCol,
        'guid'  => $guidCol,
        'item'  => $itemCol,
        'bag'   => $bagCol,
        'ench'  => $enchCol,
    ];
}

function armory_detect_item_instance_schema(PDO $pdo): ?array {
    $table = null;
    if (armory_table_exists($pdo, 'item_instance')) {
        $table = 'item_instance';
    } elseif (armory_table_exists($pdo, 'item_instances')) {
        $table = 'item_instances';
    }
    
    if (!$table) return null;
    
    $cols = armory_table_columns_map($pdo, $table);
    $entryCol = $cols['itementry'] ?? ($cols['item_id'] ?? ($cols['entry'] ?? null));
    $guidCol  = $cols['guid'] ?? ($cols['item_guid'] ?? null);
    
    if (!$entryCol || !$guidCol) return null;
    
    return [
        'table' => $table,
        'entry' => $entryCol,
        'guid'  => $guidCol
    ];
}

function armory_fetch_equipped_entries(PDO $pdoChars, int $guid): array {
    $ci = armory_detect_inventory_schema($pdoChars);
    $ii = armory_detect_item_instance_schema($pdoChars);
    if (!$ci || !$ii) return [];

    $whereBag = $ci['bag'] ? "AND ci.{$ci['bag']} = 0" : ""; // bag may not exist

    $sql = "SELECT ci.{$ci['slot']} AS slot, ii.{$ii['entry']} AS entry
            FROM {$ci['table']} ci
            JOIN {$ii['table']} ii ON ii.{$ii['guid']} = ci.{$ci['item']}
            WHERE ci.{$ci['guid']} = ?
              {$whereBag}
              AND ci.{$ci['slot']} BETWEEN 0 AND 18";

    try {
        $st = $pdoChars->prepare($sql);
        $st->execute([$guid]);
    } catch (Throwable $e) {
        error_log("armory_fetch_equipped_entries error: " . $e->getMessage());
        return [];
    }

    $out = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $s = (int)($r['slot'] ?? -1);
        $e = (int)($r['entry'] ?? 0);
        if ($s >= 0 && $s <= 18 && $e > 0) $out[$s] = $e;
    }
    return $out;
}

function armory_fetch_items(PDO $pdoWorld, array $ids): array {
    $ids = array_values(array_unique(array_filter($ids, fn($v) => $v > 0)));
    if (!$ids) return [];
    
    try {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT * FROM item_template WHERE entry IN ({$placeholders})";
        $st = $pdoWorld->prepare($sql);
        $st->execute($ids);
        
        $out = [];
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $rl = array_change_key_case($r, CASE_LOWER);
            $key = $rl['entry'] ?? ($rl['id'] ?? null);
            if ($key !== null) {
                $out[(int)$key] = $rl;
            }
        }
        return $out;
    } catch (Throwable $e) {
        error_log("armory_fetch_items error: " . $e->getMessage());
        return [];
    }
}

/* 
   Enchants & Gems - ✅ OPTION 2: Show enchant effects for sockets
 */

/**
 * Parse enchantments field - TrinityCore stores both enchants and gems in one field
 * Position 0: Permanent enchant
 * Position 6: Socket 1 enchant (gem effect)
 * Position 9: Socket 2 enchant (gem effect)  
 * Position 12: Socket 3 enchant (gem effect)
 */
function armory_parse_enchantments_field(string $enchantData): array {
    $parts = explode(' ', trim($enchantData));
    return [
        'permanent_enchant' => (int)($parts[0] ?? 0),
        'temp_enchant'      => (int)($parts[3] ?? 0),
        'socket1_enchant'   => (int)($parts[6] ?? 0),  // Changed from socket1_gem
        'socket2_enchant'   => (int)($parts[9] ?? 0),  // Changed from socket2_gem
        'socket3_enchant'   => (int)($parts[12] ?? 0), // Changed from socket3_gem
        'bonus_enchant'     => (int)($parts[18] ?? 0),
    ];
}

/**
 * Fetch enchant name from world database
 */
function armory_fetch_enchant_name(PDO $pdoWorld, int $enchantId): ?string {
    if ($enchantId <= 0) return null;
    
    // Try item_enchantment_template first (maps item enchant to spell enchant)
    try {
        $stmt = $pdoWorld->prepare("SELECT ench FROM item_enchantment_template WHERE entry = ? LIMIT 1");
        $stmt->execute([$enchantId]);
        $row = $stmt->fetch();
        if ($row) {
            $enchantId = (int)$row['ench'];
        }
    } catch (Throwable $e) {
        // Table might not exist, continue
    }
    
    // Try spell enchant tables
    $tables = ['spellitemenchantment_dbc', 'spell_item_enchantment_dbc', 'spellitemenchantment'];
    
    foreach ($tables as $table) {
        if (!armory_table_exists($pdoWorld, $table)) continue;
        
        try {
            $stmt = $pdoWorld->prepare("SELECT name_lang_1 as name FROM {$table} WHERE id = ? LIMIT 1");
            $stmt->execute([$enchantId]);
            $row = $stmt->fetch();
            
            if ($row && !empty($row['name'])) {
                return trim($row['name']);
            }
        } catch (Throwable $e) {
            continue;
        }
    }
    
    return "Enchant #{$enchantId}";
}

/**
 * Get item socket information from item_template
 */
function armory_fetch_item_sockets(PDO $pdoWorld, int $itemEntry): array {
    try {
        $stmt = $pdoWorld->prepare("
            SELECT socketColor_1, socketColor_2, socketColor_3, socketBonus
            FROM item_template 
            WHERE entry = ? 
            LIMIT 1
        ");
        $stmt->execute([$itemEntry]);
        $row = $stmt->fetch();
        
        if (!$row) return [];
        
        $sockets = [];
        for ($i = 1; $i <= 3; $i++) {
            $color = (int)($row["socketColor_{$i}"] ?? 0);
            if ($color > 0) {
                $sockets[] = [
                    'socket' => $i,
                    'color' => $color,
                ];
            }
        }
        
        return [
            'sockets' => $sockets,
            'socket_bonus' => (int)($row['socketBonus'] ?? 0),
        ];
    } catch (Throwable $e) {
        return [];
    }
}

function armory_fetch_item_enchants_and_gems(PDO $pdoChars, PDO $pdoWorld, int $guid, array $slots): array {
    $ci = armory_detect_inventory_schema($pdoChars);
    $ii = armory_detect_item_instance_schema($pdoChars);

    if (!$ci || !$ii) return ['enchants' => [], 'gems' => []];
    if (empty($ci['ench'])) {
        // Your schema dump shows it exists, but just in case:
        error_log("armory_fetch_item_enchants_and_gems: character_inventory has no enchantments column");
        return ['enchants' => [], 'gems' => []];
    }

    $whereBag = $ci['bag'] ? "AND ci.{$ci['bag']} = 0" : "";

    $sql = "SELECT
                ci.{$ci['slot']} AS slot,
                ii.{$ii['entry']} AS entry,
                ci.{$ci['ench']} AS enchantments
            FROM {$ci['table']} ci
            JOIN {$ii['table']} ii ON ii.{$ii['guid']} = ci.{$ci['item']}
            WHERE ci.{$ci['guid']} = ?
              {$whereBag}
              AND ci.{$ci['slot']} BETWEEN 0 AND 18";

    try {
        $st = $pdoChars->prepare($sql);
        $st->execute([$guid]);
    } catch (Throwable $e) {
        error_log("armory_fetch_item_enchants_and_gems error: " . $e->getMessage());
        return ['enchants' => [], 'gems' => []];
    }

    $enchants = [];
    $gems = [];

    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $slot = (int)($r['slot'] ?? -1);
        $itemEntry = (int)($r['entry'] ?? 0);
        $enchantData = trim((string)($r['enchantments'] ?? ''));

        if ($slot < 0 || $itemEntry <= 0 || $enchantData === '') continue;

        $parsed = armory_parse_enchantments_field($enchantData);

        if ($parsed['permanent_enchant'] > 0) {
            $name = armory_fetch_enchant_name($pdoWorld, $parsed['permanent_enchant']);
            if ($name) $enchants[$slot] = $name;
        }

        $socketInfo = armory_fetch_item_sockets($pdoWorld, $itemEntry);
        if (!empty($socketInfo['sockets'])) {
            $slotGems = [];

            foreach ($socketInfo['sockets'] as $socketData) {
                $socketNum = (int)$socketData['socket'];
                $enchantId = 0;

                if ($socketNum === 1) $enchantId = $parsed['socket1_enchant'];
                elseif ($socketNum === 2) $enchantId = $parsed['socket2_enchant'];
                elseif ($socketNum === 3) $enchantId = $parsed['socket3_enchant'];

                if ($enchantId > 0) {
                    $effect = armory_fetch_enchant_name($pdoWorld, $enchantId);
                    if ($effect) $slotGems[] = $effect;
                }
            }

            if ($slotGems) $gems[$slot] = $slotGems;
        }
    }

    return ['enchants' => $enchants, 'gems' => $gems];
}

/* 
   Item Sets
 */

function armory_fetch_set_info(PDO $pdoWorld, int $setId, array $equippedEntries): array {
    if ($setId <= 0) return [];
    
    // Try to find item set table
    $candidates = ['item_set_names', 'itemset', 'item_sets'];
    $table = null;
    
    foreach ($candidates as $t) {
        if (armory_table_exists($pdoWorld, $t)) {
            $table = $t;
            break;
        }
    }
    
    if (!$table) return [];
    
    try {
        $st = $pdoWorld->prepare("SELECT * FROM {$table} WHERE id = ? OR entry = ? LIMIT 1");
        $st->execute([$setId, $setId]);
        $setData = $st->fetch();
        
        if (!$setData) return [];
        
        $setData = array_change_key_case($setData, CASE_LOWER);
        $setName = (string)($setData['name'] ?? 'Item Set');
        
        // Count equipped pieces
        $setPieces = [];
        for ($i = 1; $i <= 10; $i++) {
            $itemEntry = (int)($setData['item'.$i] ?? $setData['itemid_'.$i] ?? $setData['item_'.$i] ?? 0);
            if ($itemEntry > 0) {
                $setPieces[] = $itemEntry;
            }
        }
        
        $equippedCount = count(array_intersect($setPieces, $equippedEntries));
        
        // Get set bonuses
        $bonuses = [];
        for ($i = 1; $i <= 8; $i++) {
            $reqCount = (int)($setData['threshold'.$i] ?? $setData['setthreshold_'.$i] ?? $setData['required_'.$i] ?? 0);
            $spellId = (int)($setData['spell'.$i] ?? $setData['setspell_'.$i] ?? $setData['bonus_'.$i] ?? 0);
            
            if ($reqCount > 0 && $spellId > 0) {
                // Try to get spell description
                $bonusText = armory_fetch_spell_description($pdoWorld, $spellId);
                
                $bonuses[] = [
                    'count' => $reqCount,
                    'spell' => $spellId,
                    'text' => $bonusText,
                    'active' => $equippedCount >= $reqCount
                ];
            }
        }
        
        return [
            'name' => $setName,
            'pieces' => $setPieces,
            'equipped' => $equippedCount,
            'total' => count($setPieces),
            'bonuses' => $bonuses
        ];
    } catch (Throwable $e) {
        error_log("armory_fetch_set_info error: " . $e->getMessage());
        return [];
    }
}

function armory_fetch_spell_description(PDO $pdoWorld, int $spellId): string {
    if ($spellId <= 0) return 'Set bonus';
    
    $candidates = ['spell_dbc', 'spell', 'spell_template'];
    
    foreach ($candidates as $table) {
        if (!armory_table_exists($pdoWorld, $table)) continue;
        
        $cols = armory_table_columns_map($pdoWorld, $table);
        $idCol = $cols['id'] ?? ($cols['entry'] ?? null);
        $descCol = $cols['description'] ?? ($cols['desc'] ?? ($cols['spelltooltip'] ?? null));
        
        if (!$idCol) continue;
        
        try {
            if ($descCol) {
                $st = $pdoWorld->prepare("SELECT {$descCol} AS description FROM {$table} WHERE {$idCol} = ? LIMIT 1");
            } else {
                $st = $pdoWorld->prepare("SELECT * FROM {$table} WHERE {$idCol} = ? LIMIT 1");
            }
            
            $st->execute([$spellId]);
            $result = $st->fetch();
            
            if ($result) {
                // Try various description columns
                foreach (['description', 'desc', 'spelltooltip', 'tooltip', 'effect'] as $col) {
                    if (!empty($result[$col])) {
                        $desc = trim((string)$result[$col]);
                        if ($desc) return $desc;
                    }
                }
            }
        } catch (Throwable $e) {
            continue;
        }
    }
    
    return 'Set bonus effect';
}

/* 
   Icon system
 */

function armory_normalize_icon_name(string $s): string {
    $s = trim($s); 
    if ($s === '') return '';
    $s = str_replace('\\', '/', $s);
    if (stripos($s, 'interface/icons/') !== false) {
        $s = substr($s, strripos($s, '/') + 1);
    }
    $s = preg_replace('/\.(blp|tga|png|jpg|jpeg|webp)$/i', '', $s);
    return strtolower($s);
}

function armory_find_icon_map(PDO $pdoWorld): ?array {
    // Try item_icons table first
    if (armory_table_exists($pdoWorld, 'item_icons')) {
        $cols = armory_table_columns_map($pdoWorld, 'item_icons');
        $id  = $cols['displayid'] ?? ($cols['id'] ?? null);
        $ico = $cols['icon'] ?? ($cols['inventoryicon'] ?? ($cols['texture'] ?? null));
        if ($id && $ico) {
            return ['table' => 'item_icons', 'idcol' => $id, 'iconcol' => $ico];
        }
    }
    
    // Try various DBC table names
    $candidates = [
        'itemdisplayinfo_dbc', 'itemdisplayinfo', 'ItemDisplayInfo', 
        'item_display_info', 'dbc_itemdisplayinfo', 'dbc_ItemDisplayInfo'
    ];
    
    foreach ($candidates as $table) {
        if (!armory_table_exists($pdoWorld, $table)) continue;
        
        $cols = armory_table_columns_map($pdoWorld, $table);
        $id = $cols['id'] ?? ($cols['displayid'] ?? ($cols['displayid_1'] ?? ($cols['displayid1'] ?? null)));
        $ico = $cols['icon'] ?? $cols['inventoryicon'] ?? $cols['inventory_icon'] ?? 
               $cols['texture'] ?? $cols['iconname'] ?? null;
               
        if ($id && $ico) {
            return ['table' => $table, 'idcol' => $id, 'iconcol' => $ico];
        }
    }
    
    return null;
}

/**
 * displayId -> iconName (from world.itemdisplayinfo)
 * Returns: [displayId => "inv_sword_04", ...]
 */
function armory_fetch_icons_for_displayids(PDO $pdoWorld, array $displayIds): array {
    $out = [];
    $displayIds = array_values(array_unique(array_filter(array_map('intval', $displayIds), fn($v) => $v > 0)));
    if (!$displayIds) return $out;

    // Prefer local DBC import table
    $table = 'itemdisplayinfo';
    if (!armory_table_exists($pdoWorld, $table)) return $out;

    $cols = armory_table_columns_map($pdoWorld, $table) ?: [];
    if (!$cols) return $out;

    // ID column
    $idCol = $cols['ID'] ?? $cols['id'] ?? null;
    if (!$idCol) {
        foreach ($cols as $k => $v) {
            if (strtolower($k) === 'id') { $idCol = $v; break; }
        }
    }
    if (!$idCol) return $out;

    // Icon column candidates (DBC imports vary a LOT)
    $iconCol = null;
    $iconKeys = [
        'InventoryIcon', 'inventoryicon',
        'InventoryIcon_1', 'inventoryicon_1',
        'inventoryicon0', 'inventoryicon_0',
        'Icon', 'icon', 'IconName', 'iconname',
        'invicon', 'inv_icon', 'inviconname'
    ];

    foreach ($iconKeys as $key) {
        if (isset($cols[$key])) { $iconCol = $cols[$key]; break; }
        foreach ($cols as $k => $v) {
            if (strtolower($k) === strtolower($key)) { $iconCol = $v; break 2; }
        }
    }
    if (!$iconCol) return $out;

    $ph = implode(',', array_fill(0, count($displayIds), '?'));

    try {
        $st = $pdoWorld->prepare("SELECT {$idCol} AS did, {$iconCol} AS icon FROM {$table} WHERE {$idCol} IN ($ph)");
        $st->execute($displayIds);

        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $did = (int)($r['did'] ?? 0);
            if ($did <= 0) continue;

            $icon = trim((string)($r['icon'] ?? ''));
            if ($icon === '') continue;

            // Normalize: some imports include path or extension
            $icon = str_replace(['\\','/'], '', $icon);
            $icon = preg_replace('~\.(blp|tga|png|jpg|jpeg|webp)$~i', '', $icon);
            $icon = strtolower($icon);

            $out[$did] = $icon;
        }
    } catch (Throwable $e) {
        error_log("armory_fetch_icons_for_displayids(itemdisplayinfo) failed: ".$e->getMessage());
    }

    return $out;
}

/**
 * Returns active spec info based on learned talents:
 * ['tabId'=>int, 'name'=>'Retribution', 'slug'=>'retribution', 'points'=>int]
 * or null if not resolvable.
 */
function armory_fetch_active_spec(PDO $pdoChars, PDO $pdoWorld, int $guid, int $activeGroup): ?array {
    if ($guid <= 0) return null;

    // Detect character_talent table + columns
    $talTables = ['character_talent', 'character_talents'];
    $ctTable = null;
    foreach ($talTables as $t) {
        if (armory_table_exists($pdoChars, $t)) { $ctTable = $t; break; }
    }
    if (!$ctTable) return null;

    $ctCols = armory_table_columns_map($pdoChars, $ctTable) ?: [];
    if (!$ctCols) return null;

    $findCol = function(array $cols, array $cands): ?string {
        foreach ($cands as $cand) {
            if (isset($cols[$cand])) return $cols[$cand];
            foreach ($cols as $k => $v) {
                if (strtolower($k) === strtolower($cand)) return $v;
            }
        }
        return null;
    };

    $guidCol  = $findCol($ctCols, ['guid', 'GUID']);
    $spellCol = $findCol($ctCols, ['spell', 'Spell', 'spellid', 'SpellID']);
    $grpCol   = $findCol($ctCols, ['talentGroup', 'TalentGroup', 'talent_group', 'spec', 'specMask', 'specmask']);

    if (!$guidCol || !$spellCol) return null;

    // Detect world talent + talenttab tables + columns
    $talentTable = armory_table_exists($pdoWorld, 'talent') ? 'talent' : null;
    $tabTable    = armory_table_exists($pdoWorld, 'talenttab') ? 'talenttab' : null;
    if (!$talentTable || !$tabTable) return null;

    $tCols  = armory_table_columns_map($pdoWorld, $talentTable) ?: [];
    $ttCols = armory_table_columns_map($pdoWorld, $tabTable) ?: [];
    if (!$tCols || !$ttCols) return null;

    $tabIdCol = $findCol($tCols, ['TabID', 'tabid', 'TabId']);
    if (!$tabIdCol) return null;

    // Build IN join across spell ranks (1..6)
    $rankCols = [];
    for ($i = 1; $i <= 6; $i++) {
        $c = $findCol($tCols, ["SpellRank_{$i}", "spellrank_{$i}", "SpellRank{$i}", "spellrank{$i}"]);
        if ($c) $rankCols[] = $c;
    }
    if (!$rankCols) return null;

    // talenttab name col (varies)
    $nameCol = null;
    $nameKeys = ['name_lang_enus','name_lang_1','Name_Lang_enUS','Name_Lang_1','name','Name'];
    foreach ($nameKeys as $k) {
        $nameCol = $findCol($ttCols, [$k]);
        if ($nameCol) break;
    }
    $ttIdCol = $findCol($ttCols, ['ID','id']);
    if (!$ttIdCol || !$nameCol) return null;

    // Group filtering:
    // Most TC 3.3.5 schemas use talentGroup (0/1). If missing, we just ignore group.
    $groupWhere = '';
    $params = [$guid];

    if ($grpCol) {
        // If it's specMask style, it might not be 0/1; but most cases it is talentGroup.
        $groupWhere = " AND ct.{$grpCol} = ? ";
        $params[] = $activeGroup;
    }

    // Join condition: ct.spell matches any SpellRank_X column
    $joinParts = [];
    foreach ($rankCols as $rc) $joinParts[] = "ct.{$spellCol} = t.{$rc}";
    $joinOn = '(' . implode(' OR ', $joinParts) . ')';

    // Count points per TabID
    $sql = "
        SELECT t.{$tabIdCol} AS tabId, COUNT(*) AS points
        FROM {$ctTable} ct
        JOIN {$talentTable} t ON {$joinOn}
        WHERE ct.{$guidCol} = ?
        {$groupWhere}
        GROUP BY t.{$tabIdCol}
        ORDER BY points DESC
        LIMIT 1
    ";

    try {
        $st = $pdoChars->prepare($sql);
        $st->execute($params);
        $top = $st->fetch(PDO::FETCH_ASSOC);
        if (!$top) return null;

        $tabId  = (int)($top['tabId'] ?? 0);
        $points = (int)($top['points'] ?? 0);
        if ($tabId <= 0) return null;

        // Fetch tab name
        $st2 = $pdoWorld->prepare("SELECT {$nameCol} AS name FROM {$tabTable} WHERE {$ttIdCol} = ? LIMIT 1");
        $st2->execute([$tabId]);
        $row = $st2->fetch(PDO::FETCH_ASSOC);

        $name = trim((string)($row['name'] ?? ''));
        if ($name === '') return null;

        // slug: lower, remove punctuation, spaces -> nothing (matches your filenames)
        $slug = strtolower($name);
        $slug = preg_replace('~[^a-z0-9]+~', '', $slug);

        return [
            'tabId'  => $tabId,
            'name'   => $name,
            'slug'   => $slug,
            'points' => $points,
        ];
    } catch (Throwable $e) {
        error_log("armory_fetch_active_spec failed: ".$e->getMessage());
        return null;
    }
}

function armory_fetch_entry_icon_overrides(PDO $pdoWorld, array $entries): array {
    $entries = array_values(array_unique(array_filter($entries, fn($v) => $v > 0)));
    if (!$entries) return [];
    
    $candidates = ['item_icons', 'custom_item_icons', 'item_icon_overrides'];
    $out = [];
    
    foreach ($candidates as $table) {
        if (!armory_table_exists($pdoWorld, $table)) continue;
        
        $cols = armory_table_columns_map($pdoWorld, $table);
        $idcol = $cols['entry'] ?? ($cols['item'] ?? ($cols['itementry'] ?? null));
        $iconcol = $cols['icon'] ?? ($cols['inventoryicon'] ?? ($cols['texture'] ?? $cols['iconname'] ?? null));
        
        if (!$idcol || !$iconcol) continue;

        try {
            $placeholders = implode(',', array_fill(0, count($entries), '?'));
            $sql = "SELECT {$idcol} AS entry, {$iconcol} AS icon
                    FROM {$table} 
                    WHERE {$idcol} IN ({$placeholders})";
                    
            $st = $pdoWorld->prepare($sql);
            $st->execute($entries);
            
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $e = (int)$r['entry'];
                $icon = armory_normalize_icon_name((string)$r['icon']);
                if ($e > 0 && $icon !== '') {
                    $out[$e] = $icon;
                }
            }
            
            if ($out) break; // First matching table wins
        } catch (Throwable $e) {
            continue;
        }
    }
    
    return $out;
}

function armory_row_icon_hint(array $row): string {
    $candidates = ['iconname', 'icon_name', 'inventoryicon', 'inventory_icon', 'icon', 'texture'];
    foreach ($candidates as $c) {
        if (!empty($row[$c])) {
            return armory_normalize_icon_name((string)$row[$c]);
        }
    }
    return '';
}

function armory_row_displayid(array $row): int {
    $candidates = ['displayid', 'display_id', 'displayid1', 'displayid_1', 'displayinfoid', 'display_info_id'];
    foreach ($candidates as $k) {
        if (isset($row[$k]) && (int)$row[$k] > 0) {
            return (int)$row[$k];
        }
    }
    return 0;
}

function armory_iconmeta_dir(): string {
    $dir = __DIR__ . '/../storage/iconmeta';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function armory_http_get(string $url, int $timeout = 6): ?string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'ThoriumArmory/1.0'
        ]);
        $res = curl_exec($ch);
        $ok = $res !== false && (int)curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
        curl_close($ch);
        return $ok ? (string)$res : null;
    }
    
    $ctx = stream_context_create([
        'http' => [
            'timeout' => $timeout,
            'header' => "User-Agent: ThoriumArmory/1.0\r\n"
        ]
    ]);
    $res = @file_get_contents($url, false, $ctx);
    return $res !== false ? (string)$res : null;
}

function armory_wowhead_icon_name_by_entry(int $entry, string $expansion = 'wotlk'): ?string {
    if ($entry <= 0) return null;
    
    $file = armory_iconmeta_dir() . "/{$expansion}_{$entry}.json";
    
    // Check cache (30 days)
    if (is_file($file) && (time() - filemtime($file)) < 60 * 60 * 24 * 30) {
        $json = json_decode((string)@file_get_contents($file), true);
        if (!empty($json['icon'])) {
            return armory_normalize_icon_name((string)$json['icon']);
        }
    }
    
    // Fetch from Wowhead
    $urls = [
        "https://www.wowhead.com/tooltip/item/{$entry}?dataEnv={$expansion}",
        "https://nether.wowhead.com/tooltip/item/{$entry}?dataEnv={$expansion}",
    ];
    
    foreach ($urls as $url) {
        $json = armory_http_get($url);
        if (!$json) continue;
        
        @file_put_contents($file, $json);
        $data = json_decode($json, true);
        if (!empty($data['icon'])) {
            return armory_normalize_icon_name((string)$data['icon']);
        }
    }
    
    return null;
}

function armory_icon_url_from_icon(string $icon): string {
    $icon = armory_normalize_icon_name($icon);
    if (!$icon) $icon = 'inv_misc_questionmark';
    return "https://wow.zamimg.com/images/wow/icons/large/{$icon}.jpg";
}

/* 
   Stats and totals calculations
 */

function armory_stat_get(array $row, int $index, string $kind): ?int {
    return $row["{$kind}{$index}"] ?? $row["{$kind}_{$index}"] ?? null;
}

function armory_compute_gear_totals(array $itemsById, array $slots): array {
    $totals = [
        'Strength' => 0, 'Agility' => 0, 'Stamina' => 0, 'Intellect' => 0, 'Spirit' => 0,
        'Armor' => 0, 'Block Value' => 0,
        'Attack Power' => 0, 'Ranged Attack Power' => 0, 'Feral Attack Power' => 0, 'Spell Power' => 0,
        'Hit Rating' => 0, 'Crit Rating' => 0, 'Haste Rating' => 0, 'Expertise Rating' => 0, 
        'Armor Penetration Rating' => 0, 'Resilience' => 0, 'Spell Penetration' => 0,
        'Mana per 5 sec' => 0, 'Health per 5 sec' => 0,
        'Defense Rating' => 0, 'Dodge Rating' => 0, 'Parry Rating' => 0, 'Block Rating' => 0,
    ];

    foreach ($slots as $slot => $entry) {
        $row = $itemsById[$entry] ?? null;
        if (!$row) continue;

        $totals['Armor'] += (int)($row['armor'] ?? 0);
        $totals['Block Value'] += (int)($row['block'] ?? 0);

        // Process item stats
        for ($i = 1; $i <= 10; $i++) {
            $statType = armory_stat_get($row, $i, 'stat_type');
            $statValue = armory_stat_get($row, $i, 'stat_value');
            if (!$statType || !$statValue) continue;

            $value = (int)$statValue;
            switch ((int)$statType) {
                case 3:  $totals['Agility'] += $value; break;
                case 4:  $totals['Strength'] += $value; break;
                case 5:  $totals['Intellect'] += $value; break;
                case 6:  $totals['Spirit'] += $value; break;
                case 7:  $totals['Stamina'] += $value; break;
                case 12: $totals['Defense Rating'] += $value; break;
                case 13: $totals['Dodge Rating'] += $value; break;
                case 14: $totals['Parry Rating'] += $value; break;
                case 15: $totals['Block Rating'] += $value; break;
                case 16: case 17: case 18: case 31: $totals['Hit Rating'] += $value; break;
                case 19: case 20: case 21: case 32: $totals['Crit Rating'] += $value; break;
                case 28: $totals['Block Value'] += $value; break;
                case 35: $totals['Resilience'] += $value; break;
                case 36: $totals['Haste Rating'] += $value; break;
                case 37: $totals['Expertise Rating'] += $value; break;
                case 38: $totals['Attack Power'] += $value; break;
                case 39: $totals['Ranged Attack Power'] += $value; break;
                case 40: $totals['Feral Attack Power'] += $value; break;
                case 43: $totals['Mana per 5 sec'] += $value; break;
                case 44: $totals['Armor Penetration Rating'] += $value; break;
                case 45: $totals['Spell Power'] += $value; break;
                case 46: $totals['Health per 5 sec'] += $value; break;
                case 47: $totals['Spell Penetration'] += $value; break;
            }
        }
    }
    
    return $totals;
}

function armory_fetch_base_stats(PDO $pdoWorld, int $race, int $class, int $level): ?array {
    if (!armory_table_exists($pdoWorld, 'player_levelstats')) return null;
    
    try {
        $sql = "SELECT * FROM player_levelstats WHERE race = ? AND class = ? AND level = ? LIMIT 1";
        $st = $pdoWorld->prepare($sql);
        $st->execute([$race, $class, $level]); 
        
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        
        $r = array_change_key_case($r, CASE_LOWER);

        $get = function(array $row, array $candidates): int {
            foreach ($candidates as $c) { 
                if (isset($row[$c])) return (int)$row[$c]; 
            }
            return 0;
        };

        return [
            'Strength'  => $get($r, ['str', 'strength']),
            'Agility'   => $get($r, ['agi', 'agility']),
            'Stamina'   => $get($r, ['sta', 'stamina']),
            'Intellect' => $get($r, ['inte', 'int', 'intellect']),
            'Spirit'    => $get($r, ['spi', 'spirit']),
        ];
    } catch (Throwable $e) {
        error_log("armory_fetch_base_stats error: " . $e->getMessage());
        return null;
    }
}

function armory_fetch_character_stats(PDO $pdoChars, int $guid): ?array {
    if (!armory_table_exists($pdoChars, 'character_stats')) return null;
    
    try {
        $st = $pdoChars->prepare("SELECT * FROM character_stats WHERE guid = ? LIMIT 1");
        $st->execute([$guid]); 
        $result = $st->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    } catch (Throwable $e) {
        error_log("armory_fetch_character_stats error: " . $e->getMessage());
        return null;
    }
}

function armory_cs_pick(array $row, array $keys): int {
    foreach ($keys as $k) {
        if (isset($row[$k])) return (int)$row[$k];
        $lk = strtolower($k);
        if (isset($row[$lk])) return (int)$row[$lk];
    }
    return 0;
}

function armory_totals_from_character_stats(array $stats): array {
    return [
        'Strength'  => armory_cs_pick($stats, ['strength', 'str']),
        'Agility'   => armory_cs_pick($stats, ['agility', 'agi']),
        'Stamina'   => armory_cs_pick($stats, ['stamina', 'sta']),
        'Intellect' => armory_cs_pick($stats, ['intellect', 'int', 'inte']),
        'Spirit'    => armory_cs_pick($stats, ['spirit', 'spi']),
        'Armor'     => armory_cs_pick($stats, ['armor']),

        'Attack Power'         => armory_cs_pick($stats, ['attackPower', 'meleeAttackPower', 'melee_ap', 'ap']),
        'Ranged Attack Power'  => armory_cs_pick($stats, ['rangedAttackPower', 'ranged_ap', 'rap']),
        'Feral Attack Power'   => armory_cs_pick($stats, ['feralAttackPower', 'feral_ap']),
        'Spell Power'          => armory_cs_pick($stats, ['spellPower', 'sp']),
        'Spell Penetration'    => armory_cs_pick($stats, ['spellPenetration', 'spell_penetration']),

        'Hit Rating'               => armory_cs_pick($stats, ['hitRating']),
        'Crit Rating'              => armory_cs_pick($stats, ['critRating']),
        'Haste Rating'             => armory_cs_pick($stats, ['hasteRating']),
        'Expertise Rating'         => armory_cs_pick($stats, ['expertiseRating']),
        'Armor Penetration Rating' => armory_cs_pick($stats, ['armorPenetration', 'arpRating']),
        'Resilience'               => armory_cs_pick($stats, ['resilience', 'resilienceRating']),

        'Mana per 5 sec'  => armory_cs_pick($stats, ['mp5', 'manaRegen']),
        'Health per 5 sec' => armory_cs_pick($stats, ['hp5', 'healthRegen']),

        'Block Value'   => armory_cs_pick($stats, ['blockValue']),
        'Block Rating'  => armory_cs_pick($stats, ['blockRating']),
        'Dodge Rating'  => armory_cs_pick($stats, ['dodgeRating']),
        'Parry Rating'  => armory_cs_pick($stats, ['parryRating']),
        'Defense Rating' => armory_cs_pick($stats, ['defenseRating']),
    ];
}

function armory_sum_maps(array $a, array $b): array {
    foreach ($b as $k => $v) {
        $a[$k] = (int)($a[$k] ?? 0) + (int)$v;
    }
    return $a;
}