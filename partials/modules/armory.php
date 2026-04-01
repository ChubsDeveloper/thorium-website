<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!function_exists('__thorium_root')) {
    function __thorium_root(): string {
        $dir = __DIR__;
        for ($i=0; $i<8; $i++) {
            if (is_dir($dir.'/app') && is_dir($dir.'/public')) return $dir;
            $dir = dirname($dir);
        }
        return dirname(__DIR__, 3);
    }
}

$ROOT = __thorium_root();
$armoryRepoPath = $ROOT . '/app/armory_repo.php';

if (!file_exists($armoryRepoPath)) {
    die('<div style="padding:2rem;background:#1a1a1a;color:#ff6b6b;border:2px solid #ff6b6b;margin:2rem;">
        <h2>ERROR: armory_repo.php not found!</h2>
        <p>Expected location: ' . htmlspecialchars($armoryRepoPath) . '</p>
        </div>');
}

require_once $armoryRepoPath;

function safe_html($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function armory_cached_query($key, $callback, $ttl = 300) {
    static $cache = [];
    if (isset($cache[$key]) && $cache[$key]['expires'] > time()) {
        return $cache[$key]['data'];
    }
    $data = $callback();
    $cache[$key] = ['data' => $data, 'expires' => time() + $ttl];
    return $data;
}

function armory_fetch_enchant_text_map(PDO $pdoWorld, array $ids): array {
    $out = [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
    if (!$ids) return $out;

    // Try multiple possible tables
    $tables = [
        'spellitemenchantment',
        'spellitemenchantment_dbc',
        'spell_item_enchantment_dbc',
        'spell_item_enchantment',
    ];

    // Try multiple possible "name" columns (common TC/DBC variants)
    $nameKeys = [
        'name_lang_enus',
        'name_lang_1',
        'name',
        'namelang_enus',
        'Name_Lang_enUS',
        'Name_Lang_1',
    ];

    foreach ($tables as $table) {
        if (!armory_table_exists($pdoWorld, $table)) continue;

        $cols = armory_table_columns_map($pdoWorld, $table);
        if (!$cols) continue;

        // ID column
        $idCol = $cols['id'] ?? $cols['ID'] ?? null;
        if (!$idCol) {
            // try case-insensitive match
            foreach ($cols as $k => $v) {
                if (strtolower($k) === 'id') { $idCol = $v; break; }
            }
        }
        if (!$idCol) continue;

        // Name column
        $nameCol = null;
        foreach ($nameKeys as $nk) {
            if (isset($cols[$nk])) { $nameCol = $cols[$nk]; break; }
            // case-insensitive match
            foreach ($cols as $k => $v) {
                if (strtolower($k) === strtolower($nk)) { $nameCol = $v; break 2; }
            }
        }
        if (!$nameCol) continue;

        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdoWorld->prepare("SELECT {$idCol} AS id, {$nameCol} AS name FROM {$table} WHERE {$idCol} IN ($ph)");
        $st->execute($ids);

        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $eid = (int)($r['id'] ?? 0);
            if ($eid <= 0) continue;
            $name = trim((string)($r['name'] ?? ''));
            if ($name !== '') $out[$eid] = $name;
        }

        // If we found anything, stop (best table wins)
        if (!empty($out)) return $out;
    }

    return $out;
}

function armory_get_safe_fallback_displayid(int $class, int $subclass, int $invType): int {
    if ($class === 4) {
        $armorFallbacks = [
            1 => [1 => 35601, 3 => 35611, 5 => 35601, 6 => 35410, 7 => 35611, 8 => 35409, 9 => 35413, 10 => 35411],
            2 => [1 => 35601, 3 => 35611, 5 => 35601, 6 => 35410, 7 => 35611, 8 => 35409, 9 => 35413, 10 => 35411],
            3 => [1 => 35601, 3 => 35611, 5 => 35601, 6 => 35410, 7 => 35611, 8 => 35409, 9 => 35413, 10 => 35411],
            4 => [1 => 35601, 3 => 35611, 5 => 35601, 6 => 35410, 7 => 35611, 8 => 35409, 9 => 35413, 10 => 35411],
        ];
        return $armorFallbacks[$subclass][$invType] ?? 0;
    }

    if ($class === 2) {
        $weaponFallbacks = [
            0 => 24559, 1 => 24559, 2 => 35717, 3 => 35717, 4 => 24559, 5 => 24559,
            6 => 24559, 7 => 24559, 8 => 24559, 10 => 24559, 13 => 24559, 15 => 24559,
            18 => 35717, 19 => 24559
        ];
        return $weaponFallbacks[$subclass] ?? 24559;
    }

    return 0;
}

$name  = trim((string)($_GET['name'] ?? ''));
$realm = (int)($_GET['realm'] ?? 0);

try {
    $pdoChars = armory_pdo_chars();
    $pdoWorld = armory_pdo_world();
    $pdoAuth  = armory_pdo_auth();
} catch (PDOException $e) {
    die('<div style="padding:2rem;background:#1a1a1a;color:#ff6b6b;border:2px solid #ff6b6b;margin:2rem;">
        <h2>Database Connection Error</h2>
        <p>' . htmlspecialchars($e->getMessage()) . '</p>
        </div>');
}

if (!isset($_SESSION)) {
    @session_start();
}

$u = $_SESSION['user'] ?? [];
$isAdmin = !empty($u['is_admin']) || ((int)($u['gmlevel'] ?? 0) >= 3);

$allRealms = armory_fetch_realms($pdoAuth);
$realms = $isAdmin ? $allRealms : array_filter($allRealms, fn($name, $id) => (int)$id === 1, ARRAY_FILTER_USE_BOTH);

if (empty($realms)) {
    $realms = [1 => 'Default Realm'];
}

if (!$realm) $realm = (int)(array_key_first($realms) ?: 1);
$realmName = $realms[$realm] ?? ('Realm #'.$realm);

$char = null;
$bloodmarkLevel = 0;
$artifactLevel = 0;

if ($name !== '') {
    try {
        $char = armory_fetch_character($pdoChars, $name);

        if ($char) {
            $classId = (int)$char['class'];
            $classColors = [
                1 => '#C79C6E', 2 => '#F58CBA', 3 => '#ABD473', 4 => '#FFF569', 5 => '#FFFFFF',
                6 => '#C41F3B', 7 => '#0070DE', 8 => '#69CCF0', 9 => '#9482C9', 11 => '#FF7D0A',
            ];
            $classColor = $classColors[$classId] ?? '#FFFFFF';

            try {
                $bloodmarkQuery = $pdoChars->prepare("SELECT level FROM character_bloodmarks WHERE guid = :guid");
                $bloodmarkQuery->execute(['guid' => $char['guid']]);
                $bloodmark = $bloodmarkQuery->fetch(PDO::FETCH_ASSOC);
                $bloodmarkLevel = $bloodmark ? (int)$bloodmark['level'] : 0;
            } catch (Exception $e) {
                error_log("Bloodmark fetch error: " . $e->getMessage());
            }

            try {
                $artifactQuery = $pdoChars->prepare("SELECT level FROM character_artifact_stats WHERE ownerGUID = :guid");
                $artifactQuery->execute(['guid' => $char['guid']]);
                $artifact = $artifactQuery->fetch(PDO::FETCH_ASSOC);
                $artifactLevel = $artifact ? (int)$artifact['level'] : 0;
            } catch (Exception $e) {
                error_log("Artifact fetch error: " . $e->getMessage());
            }

            $guildName = '';
            try {
                $stmt = $pdoChars->prepare("SELECT g.name as guild_name FROM guild_member gm JOIN guild g ON gm.guildid = g.guildid WHERE gm.guid = ?");
                $stmt->execute([(int)$char['guid']]);
                $guildData = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($guildData) {
                    $guildName = $guildData['guild_name'] ?? '';
                }
            } catch (Exception $e) {
                error_log("Guild fetch error: " . $e->getMessage());
            }
        }
    } catch (Exception $e) {
        error_log("Armory fetch character error: " . $e->getMessage());
    }
}

function get_slot_names(): array {
    return [
        0=>'Head', 1=>'Neck', 2=>'Shoulder', 3=>'Shirt', 4=>'Chest', 5=>'Waist', 6=>'Legs',
        7=>'Feet', 8=>'Wrist', 9=>'Hands', 10=>'Finger 1', 11=>'Finger 2', 12=>'Trinket 1',
        13=>'Trinket 2', 14=>'Back', 15=>'Main Hand', 16=>'Off Hand', 17=>'Ranged', 18=>'Tabard',
    ];
}

function armory_profession_skill_map(): array {
    return [
        164 => ['name' => 'Blacksmithing', 'type' => 'primary'],
        165 => ['name' => 'Leatherworking', 'type' => 'primary'],
        171 => ['name' => 'Alchemy', 'type' => 'primary'],
        197 => ['name' => 'Tailoring', 'type' => 'primary'],
        202 => ['name' => 'Engineering', 'type' => 'primary'],
        333 => ['name' => 'Enchanting', 'type' => 'primary'],
        755 => ['name' => 'Jewelcrafting', 'type' => 'primary'],
        773 => ['name' => 'Inscription', 'type' => 'primary'],
        182 => ['name' => 'Herbalism', 'type' => 'primary'],
        186 => ['name' => 'Mining', 'type' => 'primary'],
        393 => ['name' => 'Skinning', 'type' => 'primary'],
        129 => ['name' => 'First Aid', 'type' => 'secondary'],
        185 => ['name' => 'Cooking', 'type' => 'secondary'],
        356 => ['name' => 'Fishing', 'type' => 'secondary'],
    ];
}

function armory_fetch_professions(PDO $pdoChars, int $guid): array {
    $map = armory_profession_skill_map();
    if ($guid <= 0) return ['primary' => [], 'secondary' => []];

    $ids = array_keys($map);
    if (empty($ids)) return ['primary' => [], 'secondary' => []];

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT skill, value, max FROM character_skills WHERE guid = ? AND skill IN ($placeholders)";
    $stmt = $pdoChars->prepare($sql);
    $stmt->execute(array_merge([$guid], $ids));

    $out = ['primary' => [], 'secondary' => []];

    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $sid = (int)($r['skill'] ?? 0);
        if ($sid <= 0 || !isset($map[$sid])) continue;

        $value = (int)($r['value'] ?? 0);
        $max   = (int)($r['max'] ?? 0);
        if ($max <= 0) $max = max(75, $value);

        $info = $map[$sid];
        $row = [
            'skill' => $sid,
            'name'  => $info['name'],
            'value' => $value,
            'max'   => $max,
            'pct'   => $max > 0 ? (int)round(($value / $max) * 100) : 0,
        ];

        if (($info['type'] ?? '') === 'secondary') $out['secondary'][] = $row;
        else $out['primary'][] = $row;
    }

    usort($out['primary'], fn($a,$b) => ($b['value'] <=> $a['value']));
    usort($out['secondary'], fn($a,$b) => ($b['value'] <=> $a['value']));

    if (count($out['primary']) > 2) $out['primary'] = array_slice($out['primary'], 0, 2);

    return $out;
}

$slots = [];
$itemIds = [];
$itemsById = [];
$gearTotals = [];
$overallTotals = [];
$gearScore = 0;
$avgIlvl = 0;
$professions = ['primary' => [], 'secondary' => []];

/** NEW: enchants + gems prepared per slot */
$enchBySlot = [];        // slot => raw enchant string from item_instance.enchantments (preferred)
$extrasBySlot = [];      // slot => ['enchant' => ..., 'gems' => ...]
$enchantTextMap = [];    // enchantId => text
$gemMap = [];            // gemEnchantId => ['type'=>..,'text'=>..]

/**
 * TC-style: character_inventory stores item GUID, item_instance stores enchantments
 * This detects columns and pulls enchantments from the right place.
 */
function armory_fetch_equipped_enchantments(PDO $pdoChars, int $guid): array {
    $out = [];
    if ($guid <= 0) return $out;

    $ci = armory_detect_inventory_schema($pdoChars);
    $ii = armory_detect_item_instance_schema($pdoChars);
    if (!$ci || !$ii) return $out;

    $whereBag = $ci['bag'] ? "AND ci.{$ci['bag']} = 0" : "";

    // detect whether enchantments column exists on ii or ci
    $ciCols = armory_table_columns_map($pdoChars, $ci['table']) ?: [];
    $iiCols = armory_table_columns_map($pdoChars, $ii['table']) ?: [];

    $findCol = function(array $cols, array $cands): ?string {
        foreach ($cands as $cand) {
            if (isset($cols[$cand])) return $cols[$cand];
            foreach ($cols as $k => $v) {
                if (strtolower($k) === strtolower($cand)) return $v;
            }
        }
        return null;
    };

    $ciEnchantCol = $findCol($ciCols, ['enchantments','ench','enchant']);
    $iiEnchantCol = $findCol($iiCols, ['enchantments','ench','enchant']);

    // Prefer item_instance enchantments (TC typical)
    if ($iiEnchantCol) {
        $sql = "
            SELECT ci.{$ci['slot']} AS slot, ii.{$iiEnchantCol} AS enchantments
            FROM {$ci['table']} ci
            JOIN {$ii['table']} ii ON ii.{$ii['guid']} = ci.{$ci['item']}
            WHERE ci.{$ci['guid']} = ?
              {$whereBag}
              AND ci.{$ci['slot']} BETWEEN 0 AND 18
        ";
    } elseif ($ciEnchantCol) {
        // fallback: some schemas store it on character_inventory
        $sql = "
            SELECT ci.{$ci['slot']} AS slot, ci.{$ciEnchantCol} AS enchantments
            FROM {$ci['table']} ci
            WHERE ci.{$ci['guid']} = ?
              {$whereBag}
              AND ci.{$ci['slot']} BETWEEN 0 AND 18
        ";
    } else {
        // nowhere to read enchants from
        return $out;
    }

    try {
        $st = $pdoChars->prepare($sql);
        $st->execute([$guid]);
    } catch (Throwable $e) {
        error_log("armory_fetch_equipped_enchantments error: ".$e->getMessage());
        return $out;
    }

    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $slot = (int)($r['slot'] ?? -1);
        if ($slot < 0) continue;
        $out[$slot] = trim((string)($r['enchantments'] ?? ''));
    }

    return $out;
}

if ($char) {
    $slots = armory_parse_equipment_cache($char['equipmentCache'] ?? '');

    if (empty($slots)) {
        try {
            $invSlots = armory_fetch_equipped_entries($pdoChars, (int)$char['guid']);
            if ($invSlots) $slots = $invSlots;
        } catch (Exception $e) {
            error_log("Armory fetch equipped entries error: " . $e->getMessage());
        }
    } else {
        try {
            $invSlots = armory_fetch_equipped_entries($pdoChars, (int)$char['guid']);
            if ($invSlots) {
                foreach ($invSlots as $s => $entry) {
                    if ($entry > 0) $slots[$s] = $entry;
                }
            }
        } catch (Exception $e) {
            error_log("Armory merge inventory error: " . $e->getMessage());
        }
    }

    $itemIds = array_values(array_filter($slots));
    if (!empty($itemIds)) {
        try {
            $itemsById = armory_cached_query(
                "items_" . md5(json_encode($itemIds)),
                function() use ($pdoWorld, $itemIds) {
                    return armory_fetch_items($pdoWorld, $itemIds);
                },
                600
            );
        } catch (Exception $e) {
            error_log("Armory fetch items error: " . $e->getMessage());
        }
    }

    if ($itemsById) {
        $displayIds = [];
        foreach ($itemsById as $r) {
            $did = armory_row_displayid($r);
            if ($did > 0) $displayIds[] = $did;
        }

        try {
            $iconsByDid = armory_fetch_icons_for_displayids($pdoWorld, $displayIds);
            $entryList = array_keys($itemsById);
            $iconsByEntry = armory_fetch_entry_icon_overrides($pdoWorld, $entryList);

            foreach ($itemsById as $eid => &$r) {
                $icon = armory_row_icon_hint($r);
                if (!$icon) {
                    $did = armory_row_displayid($r);
                    if ($did && isset($iconsByDid[$did])) $icon = $iconsByDid[$did];
                }
                if (!$icon && isset($iconsByEntry[$eid])) {
                    $icon = $iconsByEntry[$eid];
                }
                $r['__icon_final'] = $icon ?: '';
                $r['__entry'] = $eid;
            }
            unset($r);
        } catch (Exception $e) {
            error_log("Armory fetch icons error: " . $e->getMessage());
        }
    }

    /**
     * ==========================================================
     * ENCHANTS + GEMS PIPELINE (ROBUST / NO-ABORT)
     *
     * Key fix:
     * - We ALWAYS build sockets from item_template, even if enchants text tables are missing.
     * - We DO NOT let a missing DBC table kill the whole pipeline.
     * ==========================================================
     */
    try {
        // 1) Fetch raw enchantment strings per slot (prefers item_instance.enchantments)
        $enchBySlot = armory_fetch_equipped_enchantments($pdoChars, (int)$char['guid']);
        $permEnchantIds = [];
        $gemEnchantIds  = [];
        $socketBonusIds = [];

        // 2) Collect enchant/gem ids from enchant string
        foreach ($enchBySlot as $slot => $encStr) {
            $encStr = trim((string)$encStr);
            if ($encStr === '') continue;

            $n = array_map('intval', preg_split('/\s+/', $encStr));

            if (!empty($n[0]))  $permEnchantIds[] = (int)$n[0];

            if (!empty($n[6]))  $gemEnchantIds[] = (int)$n[6];
            if (!empty($n[9]))  $gemEnchantIds[] = (int)$n[9];
            if (!empty($n[12])) $gemEnchantIds[] = (int)$n[12];
        }

        $permEnchantIds = array_values(array_unique(array_filter($permEnchantIds)));
        $gemEnchantIds  = array_values(array_unique(array_filter($gemEnchantIds)));

        // 3) Collect socket bonus enchant IDs from item_template rows (these exist even if no gems)
        foreach (($slots ?? []) as $slot => $entry) {
            $entry = (int)$entry;
            if ($entry <= 0) continue;
            if (empty($itemsById[$entry])) continue;

            $row = $itemsById[$entry];
            $sb = (int)($row['socketbonus'] ?? $row['socketbonusenchantid'] ?? $row['socketbonusid'] ?? 0);
            if ($sb > 0) $socketBonusIds[] = $sb;
        }
        $socketBonusIds = array_values(array_unique(array_filter($socketBonusIds)));

        // 4) Fetch enchant texts (best effort; never abort)
        $needEnchantTextIds = array_values(array_unique(array_merge($permEnchantIds, $socketBonusIds, $gemEnchantIds)));
        if (!empty($needEnchantTextIds)) {
            try {
                $enchantTextMap = armory_fetch_enchant_text_map($pdoWorld, $needEnchantTextIds);
            } catch (Throwable $e) {
                error_log("armory_fetch_enchant_text_map failed: ".$e->getMessage());
                $enchantTextMap = [];
            }
        }

        // 5) Fetch gem type map (best effort; never abort)
        try {
            $gemMap = armory_fetch_gem_enchant_map($pdoWorld, $gemEnchantIds);
        } catch (Throwable $e) {
            error_log("armory_fetch_gem_enchant_map failed: ".$e->getMessage());
            $gemMap = [];
        }

        // helper: read socket colors from LOWERCASED item_template row
        $getSock = function(array $row, int $i): int {
            $keys = [
                "socketcolor_{$i}",
                "socketcolor{$i}",
                "socket_color_{$i}",
                "socketcolor{$i}_",
                "socketcolor{$i}__",
                "socketcolor{$i}___",
                "socketcolor{$i}____",
                "socketcolor{$i}_____",
                "socketcolor{$i}______",
                "socketcolor{$i}_______",
                "socketcolor{$i}________",
                "socketcolor{$i}_________",
                "socketColor_{$i}",
                "socketColor{$i}",
            ];
            foreach ($keys as $k) {
                $lk = strtolower($k);
                if (isset($row[$lk])) return (int)$row[$lk];
                if (isset($row[$k]))  return (int)$row[$k];
            }
            return 0;
        };

        // 6) Build extras per slot — IMPORTANT: always builds sockets if the item has sockets
        foreach (($slots ?? []) as $slot => $entry) {
            $slot  = (int)$slot;
            $entry = (int)$entry;
            if ($entry <= 0) continue;
            if (empty($itemsById[$entry])) continue;

            $row = $itemsById[$entry];

            $encStr = trim((string)($enchBySlot[$slot] ?? ''));
            $n = $encStr === '' ? [] : array_map('intval', preg_split('/\s+/', $encStr));

            $permEnchantId = (int)($n[0] ?? 0);
            $gemEids = [
                (int)($n[6]  ?? 0),
                (int)($n[9]  ?? 0),
                (int)($n[12] ?? 0),
            ];

            $socketColors = [
                $getSock($row, 1),
                $getSock($row, 2),
                $getSock($row, 3),
            ];

            $extra = [];

            // Permanent enchant text
            if ($permEnchantId > 0) {
			$txt = trim((string)($enchantTextMap[$permEnchantId] ?? ''));
			if ($txt === '') $txt = "Enchant #{$permEnchantId}";
			$extra['enchant'] = ['id' => $permEnchantId, 'text' => $txt];
		}

            // Gems + sockets (show sockets even when empty)
            $gemList = [];
            $hasSockets = false;
            $allMatch = true;

            for ($i = 0; $i < 3; $i++) {
                $sc = (int)($socketColors[$i] ?? 0);
                if ($sc <= 0) continue;

                $hasSockets = true;

                $gemEnchantId = (int)($gemEids[$i] ?? 0);
                $gem = $gemEnchantId > 0 ? ($gemMap[$gemEnchantId] ?? null) : null;

                $gemText = $gem ? trim((string)($gem['text'] ?? '')) : '';
                if ($gemText === '' && $gemEnchantId > 0) {
                    $gemText = trim((string)($enchantTextMap[$gemEnchantId] ?? ''));
                }
                if ($gemText === '' && $gemEnchantId > 0) {
                    $gemText = "Gem #{$gemEnchantId}";
                }

                $gemType = $gem ? (int)($gem['type'] ?? 0) : 0;

                if ($gemEnchantId <= 0) {
                    $matches = false; // empty socket
                } else {
                    $matches = ($gemType > 0) ? armory_gem_matches_socket($gemType, $sc) : true;
                }
                if (!$matches) $allMatch = false;

                $gemList[] = [
                    'socketColor' => $sc,
                    'socketLabel' => armory_socket_color_label($sc),
                    'enchantId'   => $gemEnchantId,
                    'text'        => $gemText,
                    'matches'     => $matches,
					'gemType'     => $gemType,
                ];
            }

            // Socket bonus (only if the item has sockets OR has any gemEnchant ids)
            if ($hasSockets || array_filter($gemEids)) {
                $socketBonusId = (int)($row['socketbonus'] ?? $row['socketbonusenchantid'] ?? $row['socketbonusid'] ?? 0);
$socketBonusText = $socketBonusId > 0 ? trim((string)($enchantTextMap[$socketBonusId] ?? '')) : '';
if ($socketBonusText === '' && $socketBonusId > 0) $socketBonusText = "Enchant #{$socketBonusId}";

$extra['gems'] = [
  'list'         => $gemList,
  'socketBonus'  => $socketBonusText,
  'socketBonusId'=> $socketBonusId,              // ✅ ADD THIS
  'bonusActive'  => ($socketBonusText !== '' && $allMatch && $hasSockets),
];
            }

            // Only store if we have something
            if (!empty($extra['enchant']) || !empty($extra['gems'])) {
                $extrasBySlot[$slot] = $extra;
            }
        }

    } catch (Throwable $e) {
        error_log("Armory enchants/gems pipeline error: " . $e->getMessage());
    }

    try {
        $gearTotals = armory_compute_gear_totals($itemsById, $slots);
        $baseTotals = armory_fetch_base_stats($pdoWorld, (int)$char['race'], (int)$char['class'], (int)$char['level']);
        $liveRow = armory_fetch_character_stats($pdoChars, (int)$char['guid']);
        $liveTotals = $liveRow ? armory_totals_from_character_stats($liveRow) : null;

        if ($liveTotals) {
            $overallTotals = $liveTotals;
            foreach ([
                'Hit Rating','Crit Rating','Haste Rating','Expertise Rating',
                'Armor Penetration Rating','Resilience','Spell Penetration',
                'Defense Rating','Dodge Rating','Parry Rating','Block Rating','Block Value'
            ] as $k) {
                if (empty($overallTotals[$k]) && !empty($gearTotals[$k])) {
                    $overallTotals[$k] = (int)$gearTotals[$k];
                }
            }
        } else {
            $overallTotals = $baseTotals ? armory_sum_maps($baseTotals, $gearTotals,) : $gearTotals;
        }

        $gearScore = calculate_gear_score($itemsById, $slots);
        $avgIlvl = calculate_avg_ilvl($itemsById, $slots);
    } catch (Exception $e) {
        error_log("Armory calculate stats error: " . $e->getMessage());
    }

// ===== Build enchant/gem/socketBonus contribution totals (AFTER extrasBySlot is built) =====
$enchantTotals = [];
$gemTotals = [];
$socketBonusTotals = [];

// Collect all ids we might need effect data for
$permIds = [];
$gemIds  = [];
$sbIds   = [];

foreach (($extrasBySlot ?? []) as $slot => $extra) {
    if (!empty($extra['enchant']['id'])) {
        $permIds[] = (int)$extra['enchant']['id'];
    }

    if (!empty($extra['gems']['list']) && is_array($extra['gems']['list'])) {
        foreach ($extra['gems']['list'] as $g) {
            $gid = (int)($g['enchantId'] ?? 0);
            if ($gid > 0) $gemIds[] = $gid;
        }
    }

    if (!empty($extra['gems']['socketBonusId'])) {
        $sbIds[] = (int)$extra['gems']['socketBonusId'];
    }
}

$allEnchantIdsForEffects = array_values(array_unique(array_filter(array_merge($permIds, $gemIds, $sbIds))));
$enchantEffects = $allEnchantIdsForEffects ? armory_fetch_enchant_stat_effects($pdoWorld, $allEnchantIdsForEffects) : [];

// Sum totals
foreach (($extrasBySlot ?? []) as $slot => $extra) {
    // Permanent enchant
    $eid = (int)($extra['enchant']['id'] ?? 0);
    if ($eid > 0 && !empty($enchantEffects[$eid])) {
        foreach ($enchantEffects[$eid] as $eff) {
            armory_add_to_map($enchantTotals, (string)$eff['stat'], (int)$eff['amount']);
        }
    }

    // Gems
    if (!empty($extra['gems']['list']) && is_array($extra['gems']['list'])) {
        foreach ($extra['gems']['list'] as $g) {
            $geid = (int)($g['enchantId'] ?? 0);
            if ($geid > 0 && !empty($enchantEffects[$geid])) {
                foreach ($enchantEffects[$geid] as $eff) {
                    armory_add_to_map($gemTotals, (string)$eff['stat'], (int)$eff['amount']);
                }
            }
        }
    }

    // Socket bonus only if active
    if (!empty($extra['gems']['bonusActive'])) {
        $sbId = (int)($extra['gems']['socketBonusId'] ?? 0);
        if ($sbId > 0 && !empty($enchantEffects[$sbId])) {
            foreach ($enchantEffects[$sbId] as $eff) {
                armory_add_to_map($socketBonusTotals, (string)$eff['stat'], (int)$eff['amount']);
            }
        }
    }
}

$extrasTotals = armory_sum_maps(
    $enchantTotals,
    armory_sum_maps($gemTotals, $socketBonusTotals)
);

$basePlusGear = armory_sum_maps($baseTotals ?? [], $gearTotals ?? []);

// If no live totals, start from base+gear
if (empty($liveTotals)) {
    $overallTotals = $basePlusGear;

    foreach ($extrasTotals as $stat => $extraVal) {
        $extraVal = (int)$extraVal;
        if ($extraVal === 0) continue;

        $bg = (int)($basePlusGear[$stat] ?? 0);

        $alreadyIncluded = $bg - (int)(($baseTotals[$stat] ?? 0) + ($gearTotals[$stat] ?? 0));
        $overallTotals[$stat] = $bg + $extraVal;
    }
}


$statBreakdowns = [];

$allStats = array_unique(array_merge(
    array_keys($baseTotals ?? []),
    array_keys($gearTotals ?? []),
    array_keys($overallTotals ?? []),
    array_keys($enchantTotals ?? []),
    array_keys($gemTotals ?? []),
    array_keys($socketBonusTotals ?? [])
));

foreach ($allStats as $stat) {
    $base   = (int)($baseTotals[$stat] ?? 0);
    $gear   = (int)($gearTotals[$stat] ?? 0);
    $ench   = (int)($enchantTotals[$stat] ?? 0);
    $gems   = (int)($gemTotals[$stat] ?? 0);
    $sockB  = (int)($socketBonusTotals[$stat] ?? 0);
    $total  = (int)($overallTotals[$stat] ?? 0);

    if ($total === 0 && ($base+$gear+$ench+$gems+$sockB) === 0) continue;

    $bonus = $total - ($base + $gear + $ench + $gems + $sockB);

    $statBreakdowns[$stat] = [
        'gear'       => $gear,
        'enchants'   => $ench,
        'gems'       => $gems,
        'socketBonus'=> $sockB,
        'bonus'      => $bonus, // can be negative if totals don't align (still useful for debugging)
        'total'      => $total,
    ];
}

    try {
        $professions = armory_fetch_professions($pdoChars, (int)$char['guid']);
    } catch (Exception $e) {
        error_log("Profession fetch error: " . $e->getMessage());
    }
}

function calculate_gear_score(array $itemsById, array $slots): int {
    $totalScore = 0;
    $slotMultipliers = [
        0 => 1.0, 1 => 0.5625, 2 => 1.0, 3 => 0.0, 4 => 1.0, 5 => 0.5625, 6 => 1.0, 7 => 0.75,
        8 => 0.5625, 9 => 0.75, 10 => 0.5625, 11 => 0.5625, 12 => 0.5625, 13 => 0.5625,
        14 => 0.5625, 15 => 2.0, 16 => 1.0, 17 => 0.25, 18 => 0.0
    ];

    foreach ($slots as $slot => $entry) {
        if (!$entry || !isset($itemsById[$entry]) || !isset($slotMultipliers[$slot])) continue;
        $multiplier = $slotMultipliers[$slot];
        if ($multiplier <= 0) continue;
        $item = $itemsById[$entry];
        $ilvl = (int)($item['itemlevel'] ?? 0);
        $quality = (int)($item['quality'] ?? 1);
        if ($ilvl > 0) {
            $baseScore = $ilvl;
            if ($quality >= 4) $baseScore *= 1.3;
            elseif ($quality >= 3) $baseScore *= 1.1;
            $totalScore += $baseScore * $multiplier;
        }
    }
    return (int)($totalScore);
}

function calculate_avg_ilvl(array $itemsById, array $slots): float {
    $total = 0;
    $count = 0;
    foreach ($slots as $slot => $entry) {
        if ($slot === 3 || $slot === 18) continue;
        if ($entry && isset($itemsById[$entry])) {
            $ilvl = (int)($itemsById[$entry]['itemlevel'] ?? 0);
            if ($ilvl > 0) {
                $total += $ilvl;
                $count++;
            }
        }
    }
    return $count > 0 ? round($total / $count, 1) : 0;
}

function get_quality_class(int $q): string {
    return [0=>'q-0',1=>'q-1',2=>'q-2',3=>'q-3',4=>'q-4',5=>'q-5',6=>'q-6',7=>'q-7'][$q] ?? 'q-1';
}

function get_quality_color_hex(int $q): string {
    return [0=>'#9d9d9d',1=>'#ffffff',2=>'#1eff00',3=>'#0070dd',4=>'#a335ee',5=>'#ff8000',6=>'#e6cc80',7=>'#00ccff'][$q] ?? '#ffffff';
}

function get_class_name(int $id): string {
    return [1=>'Warrior',2=>'Paladin',3=>'Hunter',4=>'Rogue',5=>'Priest',6=>'Death Knight',7=>'Shaman',8=>'Mage',9=>'Warlock',11=>'Druid'][$id] ?? 'Unknown';
}

function get_race_name(int $id): string  {
    return [1=>'Human',2=>'Orc',3=>'Dwarf',4=>'Night Elf',5=>'Undead',6=>'Tauren',7=>'Gnome',8=>'Troll',10=>'Blood Elf',11=>'Draenei'][$id] ?? 'Unknown';
}

function get_gender_name(int $id): string {
    return [0=>'Male', 1=>'Female'][$id] ?? 'Unknown';
}

function armory_get_generic_icon(int $inventoryType, int $class, int $subclass): string {
    $iconMap = [
        1 => 'inv_helmet_06', 2 => 'inv_jewelry_necklace_01', 3 => 'inv_shoulder_02', 4 => 'inv_shirt_01',
        5 => 'inv_chest_chain', 6 => 'inv_belt_01', 7 => 'inv_pants_01', 8 => 'inv_boots_01',
        9 => 'inv_bracer_01', 10 => 'inv_gauntlets_04', 11 => 'inv_jewelry_ring_01', 12 => 'inv_jewelry_talisman_01',
        14 => 'inv_shield_05', 15 => 'inv_weapon_bow_01', 16 => 'inv_misc_cape_01', 18 => 'inv_misc_bag_07',
        19 => 'inv_banner_01', 20 => 'inv_chest_cloth_01', 23 => 'inv_misc_book_07', 24 => 'inv_ammo_arrow_01',
        25 => 'inv_throwingknife_01'
    ];

    if ($class == 4 && in_array($inventoryType, [1, 3, 5, 6, 7, 8, 9, 10, 20])) {
        $armorIcons = [
            1 => [1 => 'inv_helmet_08', 3 => 'inv_shoulder_02', 5 => 'inv_chest_cloth_07', 6 => 'inv_belt_01',
                  7 => 'inv_pants_08', 8 => 'inv_boots_cloth_05', 9 => 'inv_bracer_07', 10 => 'inv_gauntlets_17', 20 => 'inv_chest_cloth_01'],
            2 => [1 => 'inv_helmet_17', 3 => 'inv_shoulder_23', 5 => 'inv_chest_leather_03', 6 => 'inv_belt_16',
                  7 => 'inv_pants_09', 8 => 'inv_boots_08', 9 => 'inv_bracer_07', 10 => 'inv_gauntlets_25'],
            3 => [1 => 'inv_helmet_09', 3 => 'inv_shoulder_01', 5 => 'inv_chest_chain_03', 6 => 'inv_belt_03',
                  7 => 'inv_pants_03', 8 => 'inv_boots_chain_01', 9 => 'inv_bracer_07', 10 => 'inv_gauntlets_10'],
            4 => [1 => 'inv_helmet_74', 3 => 'inv_shoulder_37', 5 => 'inv_chest_plate06', 6 => 'inv_belt_09',
                  7 => 'inv_pants_04', 8 => 'inv_boots_plate_01', 9 => 'inv_bracer_18', 10 => 'inv_gauntlets_29']
        ];
        if (isset($armorIcons[$subclass][$inventoryType])) {
            return $armorIcons[$subclass][$inventoryType];
        }
    }

    if (in_array($inventoryType, [13, 17, 21, 22, 26]) && $class == 2) {
        $weaponIcons = [
            0 => 'inv_axe_01', 1 => 'inv_axe_09', 2 => 'inv_weapon_bow_01', 3 => 'inv_weapon_rifle_01',
            4 => 'inv_mace_01', 5 => 'inv_hammer_09', 6 => 'inv_sword_04', 7 => 'inv_sword_04',
            8 => 'inv_sword_08', 10 => 'inv_staff_08', 13 => 'inv_weapon_shortblade_01', 15 => 'inv_knife_01',
            18 => 'inv_weapon_crossbow_01', 19 => 'inv_wand_01'
        ];
        return $weaponIcons[$subclass] ?? 'inv_sword_04';
    }

    return $iconMap[$inventoryType] ?? 'inv_misc_questionmark';
}

function armory_ms_to_cd(int $ms): string {
    if ($ms <= 0) return '';
    $s = (int)round($ms / 1000);
    if ($s < 60) return $s . ' sec cooldown';
    $m = (int)round($s / 60);
    if ($m < 60) return $m . ' min cooldown';
    $h = (int)round($m / 60);
    return $h . ' hr cooldown';
}

/**
 * TrinityCore ItemSpelltriggerType mapping:
 * 0=ON_USE, 1=ON_EQUIP, 2=CHANCE_ON_HIT, 4=SOULSTONE, 5=ON_NO_DELAY_USE, 6=LEARN_SPELL_ID
 */
function armory_item_spell_trigger_label(int $trigger): string {
    return match ($trigger) {
        0 => 'Use',
        1 => 'Equip',
        2 => 'Chance on hit',
        4 => 'Soulstone',
        5 => 'Use',
        6 => 'Teaches you',
        default => 'Effect',
    };
}


/**
 * Enchantments are 12 triples (36 ints). Gems are triples #3-#5 => indices 6, 9, 12 (0-based)
 */
function armory_extract_socket_gem_enchant_ids(string $enchantments): array {
    $enchantments = trim($enchantments);
    if ($enchantments === '') return [0,0,0];

    $nums = preg_split('/\s+/', $enchantments);
    $n = array_map('intval', $nums);

    return [
        $n[6]  ?? 0, // gem1 enchantId
        $n[9]  ?? 0, // gem2 enchantId
        $n[12] ?? 0, // gem3 enchantId
    ];
}

/**
 * Fetch enchantId -> gem properties (type) + gem enchant text
 */
function armory_fetch_gem_enchant_map(PDO $pdoWorld, array $enchantIds): array {
    $out = [];
    $enchantIds = array_values(array_unique(array_filter(array_map('intval', $enchantIds), fn($v) => $v > 0)));
    if (!$enchantIds) return $out;

    $tables = [
        'gemproperties',
        'gemproperties_dbc',
        'gem_properties_dbc',
        'gem_properties',
    ];

    foreach ($tables as $table) {
        if (!armory_table_exists($pdoWorld, $table)) continue;

        $cols = armory_table_columns_map($pdoWorld, $table);
        if (!$cols) continue;

        // Find Enchant_Id column
        $enchantCol = null;
        foreach ($cols as $k => $v) {
            $lk = strtolower($k);
            if ($lk === 'enchant_id' || $lk === 'enchantid' || $lk === 'enchant') { $enchantCol = $v; break; }
        }
        if (!$enchantCol && isset($cols['Enchant_Id'])) $enchantCol = $cols['Enchant_Id'];
        if (!$enchantCol) continue;

        // Find Type column
        $typeCol = null;
        foreach ($cols as $k => $v) {
            if (strtolower($k) === 'type') { $typeCol = $v; break; }
        }
        if (!$typeCol && isset($cols['Type'])) $typeCol = $cols['Type'];
        if (!$typeCol) continue;

        $ph = implode(',', array_fill(0, count($enchantIds), '?'));

        try {
            $stmt = $pdoWorld->prepare("
                SELECT {$enchantCol} AS enchantId, {$typeCol} AS gemType
                FROM {$table}
                WHERE {$enchantCol} IN ($ph)
            ");
            $stmt->execute($enchantIds);

            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $eid = (int)($r['enchantId'] ?? 0);
                if ($eid <= 0) continue;
                $out[$eid] = [
                    'enchantId' => $eid,
                    'type'      => (int)($r['gemType'] ?? 0),
                    'text'      => '', // text resolved via enchantTextMap
                ];
            }

            if (!empty($out)) return $out;

        } catch (Exception $e) {
            error_log("armory_fetch_gem_enchant_map query failed on {$table}: ".$e->getMessage());
        }
    }

    return $out;
}

function armory_socket_color_label(int $socketColor): string {
    return match ($socketColor) {
        1 => 'Meta Socket',
        2 => 'Red Socket',
        3 => 'Yellow Socket',
        4 => 'Blue Socket',
        default => '',
    };
}

function armory_socket_color_bit(int $socketColor): int {
    // 1=Meta, 2=Red, 4=Yellow, 8=Blue
    return match ($socketColor) {
        1 => 1,
        2 => 2,
        3 => 4,
        4 => 8,
        default => 0,
    };
}

function armory_gem_matches_socket(int $gemType, int $socketColor): bool {
    $bit = armory_socket_color_bit($socketColor);
    if ($bit <= 0) return false;
    return ($gemType & $bit) !== 0;
}


function armory_spell_fetch_world(PDO $pdoWorld, int $spellId): ?array {
    static $memo = [];

    if ($spellId <= 0) return null;
    if (array_key_exists($spellId, $memo)) return $memo[$spellId];

    try {
        $stmt = $pdoWorld->prepare("
            SELECT
                ID,
                SpellName0        AS name,
                SpellToolTip0     AS tooltip,
                SpellDescription0 AS descr,

                ProcChance,
                DurationIndex,

                EffectBasePoints1, EffectBasePoints2, EffectBasePoints3,
                EffectAmplitude1,  EffectAmplitude2,  EffectAmplitude3
            FROM spell
            WHERE ID = ?
            LIMIT 1
        ");
        $stmt->execute([$spellId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) return $memo[$spellId] = null;

        $row['name']    = trim((string)($row['name'] ?? ''));
        $row['tooltip'] = trim((string)($row['tooltip'] ?? ''));
        $row['descr']   = trim((string)($row['descr'] ?? ''));

        $row['ProcChance']    = (int)($row['ProcChance'] ?? 0);
        $row['DurationIndex'] = (int)($row['DurationIndex'] ?? 0);

        return $memo[$spellId] = $row;

    } catch (Exception $e) {
        return $memo[$spellId] = null;
    }
}

function armory_build_item_spell_lines(PDO $pdoWorld, array $r): array {
    $lines = [];

    for ($i = 1; $i <= 5; $i++) {
        $sid = (int)($r["spellid_$i"] ?? 0);
        if ($sid <= 0) continue;

        $trigger = (int)($r["spelltrigger_$i"] ?? 0);
        $charges = (int)($r["spellcharges_$i"] ?? 0);
        $ppm     = (float)($r["spellppmRate_$i"] ?? 0);

        $spellCdMs = (int)($r["spellcooldown_$i"] ?? 0);
        $catCdMs   = (int)($r["spellcategorycooldown_$i"] ?? 0);
        $cdText    = armory_ms_to_cd(max($spellCdMs, $catCdMs));

        $label = armory_item_spell_trigger_label($trigger);

        $spell = armory_spell_fetch_world($pdoWorld, $sid);
        $spellName = $spell ? (string)$spell['name'] : '';
        $spellTip  = $spell ? (string)$spell['tooltip'] : '';
        $spellDesc = $spell ? (string)$spell['descr'] : '';

        $best = $spellTip !== '' ? $spellTip : ($spellDesc !== '' ? $spellDesc : $spellName);
        if ($best === '') continue;

        $best = armory_spell_format_text($pdoWorld, $spell, $best);
        if ($best === '') $best = "Spell #{$sid}";

        $text = nl2br(parse_wow_color_codes_safe($best));

        $tail = [];

        if ($trigger === 2 && $ppm > 0) {
            $ppmStr = rtrim(rtrim(number_format($ppm, 2, '.', ''), '0'), '.');
            $tail[] = $ppmStr . ' PPM';
        }

        if ($charges !== 0) {
            if ($charges > 0) $tail[] = $charges . ' charge' . ($charges === 1 ? '' : 's');
            else {
                $n = abs($charges);
                $tail[] = 'Consumes after ' . $n . ' use' . ($n === 1 ? '' : 's');
            }
        }

        if ($cdText !== '') $tail[] = $cdText;

        $suffix = $tail
            ? ' <span class="tt-effect-meta">(' . implode(', ', $tail) . ')</span>'
            : '';

        $lines[] = htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . ': ' . $text . $suffix;
    }

    return $lines;
}

function armory_spell_format_text(PDO $pdoWorld, array $spellRow, string $text, int $depth = 0): string {
    if ($text === '' || $depth > 2) return $text;

    $getBp = function(array $row, int $n): int {
        $k = "EffectBasePoints{$n}";
        $v = (int)($row[$k] ?? 0);
        return $v + 1;
    };

    $getAmpSec = function(array $row, int $n): float {
        $k = "EffectAmplitude{$n}";
        $ms = (int)($row[$k] ?? 0);
        return $ms > 0 ? ($ms / 1000) : 0;
    };

    $fmtNum = function($v): string {
        if (is_float($v)) {
            return (abs($v - round($v)) < 0.0001)
                ? (string)(int)round($v)
                : rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
        }
        return (string)(int)$v;
    };

    $text = preg_replace_callback('/\$(\d{2,10})([sta])([1-3])\b/', function($m) use ($pdoWorld, $getBp, $getAmpSec, $fmtNum) {
        $refId = (int)$m[1];
        $kind  = $m[2];
        $idx   = (int)$m[3];

        $ref = armory_spell_fetch_world($pdoWorld, $refId);
        if (!$ref) return $m[0];

        if ($kind === 's') return $fmtNum($getBp($ref, $idx));
        if ($kind === 't' || $kind === 'a') {
            $v = $getAmpSec($ref, $idx);
            return $v > 0 ? $fmtNum($v) : $m[0];
        }
        return $m[0];
    }, $text);

    $text = preg_replace_callback('/\$(\d{2,10})d\b/', function($m) use ($pdoWorld) {
        $refId = (int)$m[1];
        $ref = armory_spell_fetch_world($pdoWorld, $refId);
        if (!$ref) return $m[0];

        $ms = armory_spell_duration_ms($pdoWorld, $ref);
        $dur = armory_format_duration_from_ms($ms);
        if ($dur !== '') {
    return $dur;
}

return 'several seconds';

    }, $text);

    $text = preg_replace_callback('/\$([sta])([1-3])\b/', function($m) use ($spellRow, $getBp, $getAmpSec, $fmtNum) {
        $kind = $m[1];
        $idx  = (int)$m[2];

        if ($kind === 's') return $fmtNum($getBp($spellRow, $idx));
        if ($kind === 't' || $kind === 'a') {
            $v = $getAmpSec($spellRow, $idx);
            return $v > 0 ? $fmtNum($v) : $m[0];
        }
        return $m[0];
    }, $text);

    $text = preg_replace_callback('/\$h\b/', function() use ($spellRow) {
        $h = (int)($spellRow['ProcChance'] ?? 0);
        return $h > 0 ? (string)$h : '$h';
    }, $text);

    $text = preg_replace_callback('/\$d\b/', function() use ($pdoWorld, $spellRow) {
        $ms = armory_spell_duration_ms($pdoWorld, $spellRow);
        $dur = armory_format_duration_from_ms($ms);
        return $dur !== '' ? $dur : '$d';
    }, $text);

    return $text;
}

function armory_spell_duration_ms(PDO $pdoWorld, array $spellRow): int {
    $idx = (int)($spellRow['DurationIndex'] ?? 0);
    if ($idx <= 0) return 0;

    static $cache = [];
    if (isset($cache[$idx])) return $cache[$idx];

    try {
        $st = $pdoWorld->prepare("
            SELECT BaseDuration, MaximumDuration
            FROM spellduration
            WHERE ID = ?
            LIMIT 1
        ");
        $st->execute([$idx]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) return $cache[$idx] = 0;

        $base = (int)($row['BaseDuration'] ?? 0);
        $max  = (int)($row['MaximumDuration'] ?? 0);
        $ms = $base > 0 ? $base : $max;

        if ($ms < 0) return $cache[$idx] = -1;
        return $cache[$idx] = $ms;

    } catch (Exception $e) {
        return $cache[$idx] = 0;
    }
}

function armory_format_duration_from_ms(int $ms): string {
    if ($ms === -1) return 'until cancelled';
    if ($ms <= 0) return '';

    $sec = (int)round($ms / 1000);
    if ($sec < 60) return $sec . ' sec';
    $min = (int)round($sec / 60);
    if ($min < 60) return $min . ' min';
    $hr = (int)round($min / 60);
    return $hr . ' hr';
}

function armory_socket_icon_url(int $socketColor): string {
    return match ($socketColor) {
        1 => 'inv_jewelcrafting_shadowspirit_02', // Meta
        2 => 'inv_jewelcrafting_gem_37', // Red
        3 => 'inv_jewelcrafting_gem_38', // Yellow
        4 => 'inv_jewelcrafting_gem_42', // Blue
        default => '',
    };
}

function armory_socket_border_hex(int $socketColor): string {
    return match ($socketColor) {
        1 => '#a070ff', // meta-ish
        2 => '#ff4040',
        3 => '#ffd100',
        4 => '#3aa6ff',
        default => '#666',
    };
}

function armory_gem_type_icon_url(int $gemType): string {
    return match ($gemType) {
        1  => 'inv_jewelcrafting_shadowspirit_02', // Meta
        2  => 'inv_jewelcrafting_gem_37',          // Red
        4  => 'inv_jewelcrafting_gem_38',          // Yellow
        8  => 'inv_jewelcrafting_gem_42',          // Blue
        6  => 'inv_jewelcrafting_gem_39',          // Orange
        10 => 'inv_jewelcrafting_gem_40',          // Purple
        12 => 'inv_jewelcrafting_gem_41',          // Green
        14 => 'inv_jewelcrafting_gem_36',          // Prismatic (optional)
        default => '',
    };
}

function armory_stat_label_from_type(int $t): string {
    return match ($t) {
        3 => 'Agility',
        4 => 'Strength',
        5 => 'Intellect',
        6 => 'Spirit',
        7 => 'Stamina',

        31,16,17,18 => 'Hit Rating',
        32,19,20,21 => 'Crit Rating',
        36 => 'Haste Rating',
        37 => 'Expertise Rating',

        38 => 'Attack Power',
        39 => 'Ranged Attack Power',
        45 => 'Spell Power',
        35 => 'Resilience',
        12 => 'Defense Rating',
        13 => 'Dodge Rating',
        14 => 'Parry Rating',
        15 => 'Block Rating',
        44 => 'Armor Penetration Rating',
        43 => 'Mana per 5 sec.',
        47 => 'Spell Penetration',

        default => '',
    };
}

/**
 * Reads STAT-type effects from SpellItemEnchantment-like tables.
 * Works with common TC-exported schemas (Type_1/Amount_1/SpellID_1 variants).
 */
function armory_fetch_enchant_stat_effects(PDO $pdoWorld, array $enchantIds): array {
    $out = [];
    $enchantIds = array_values(array_unique(array_filter(array_map('intval', $enchantIds), fn($v)=>$v>0)));
    if (!$enchantIds) return $out;

    $tables = [
        'spellitemenchantment',
        'spellitemenchantment_dbc',
        'spell_item_enchantment',
        'spell_item_enchantment_dbc',
    ];

    foreach ($tables as $table) {
        if (!armory_table_exists($pdoWorld, $table)) continue;

        $cols = armory_table_columns_map($pdoWorld, $table);
        if (!$cols) continue;

        // ID col
        $idCol = $cols['ID'] ?? $cols['id'] ?? null;
        if (!$idCol) {
            foreach ($cols as $k=>$v) {
                if (strtolower($k)==='id') { $idCol=$v; break; }
            }
        }
        if (!$idCol) continue;

        // Column sets
        $effCols  = [];
        $argCols  = [];
        $minCols  = [];
        $maxCols  = [];

        $typeCols = [];
        $amtCols  = [];
        $sidCols  = []; // <-- old style "arg" is usually SpellID_#

        for ($i=1; $i<=3; $i++) {
            // TC/DBC style
            $effCols[$i] = $cols["Effect_{$i}"] ?? $cols["effect_{$i}"] ?? $cols["effect{$i}"] ?? $cols["Effect{$i}"] ?? null;
            $argCols[$i] = $cols["EffectArg_{$i}"] ?? $cols["effectarg_{$i}"] ?? $cols["effectarg{$i}"] ?? $cols["EffectArg{$i}"] ?? null;
            $minCols[$i] = $cols["EffectPointsMin_{$i}"] ?? $cols["effectpointsmin_{$i}"] ?? $cols["EffectPointsMin{$i}"] ?? null;
            $maxCols[$i] = $cols["EffectPointsMax_{$i}"] ?? $cols["effectpointsmax_{$i}"] ?? $cols["EffectPointsMax{$i}"] ?? null;

            // Older exports
            $typeCols[$i] = $cols["Type_{$i}"] ?? $cols["type_{$i}"] ?? $cols["type{$i}"] ?? $cols["Type{$i}"] ?? null;
            $amtCols[$i]  = $cols["Amount_{$i}"] ?? $cols["amount_{$i}"] ?? $cols["amount{$i}"] ?? $cols["Amount{$i}"] ?? null;
            $sidCols[$i]  = $cols["SpellID_{$i}"] ?? $cols["spellid_{$i}"] ?? $cols["spellid{$i}"] ?? $cols["SpellID{$i}"] ?? null;
        }

        // Need at least one usable triplet set
        $hasTC  = false;
        $hasOld = false;
        for ($i=1; $i<=3; $i++) {
            if ($effCols[$i] && ($minCols[$i] || $maxCols[$i]) && $argCols[$i]) $hasTC = true;
            if ($typeCols[$i] && $amtCols[$i] && $sidCols[$i]) $hasOld = true;
        }
        if (!$hasTC && !$hasOld) continue;

        $ph = implode(',', array_fill(0, count($enchantIds), '?'));

        $select = "{$idCol} AS id";
        for ($i=1; $i<=3; $i++) {
            if ($effCols[$i])  $select .= ", {$effCols[$i]}  AS eff{$i}";
            if ($argCols[$i])  $select .= ", {$argCols[$i]}  AS arg{$i}";
            if ($minCols[$i])  $select .= ", {$minCols[$i]}  AS min{$i}";
            if ($maxCols[$i])  $select .= ", {$maxCols[$i]}  AS max{$i}";

            if ($typeCols[$i]) $select .= ", {$typeCols[$i]} AS type{$i}";
            if ($amtCols[$i])  $select .= ", {$amtCols[$i]}  AS amt{$i}";
            if ($sidCols[$i])  $select .= ", {$sidCols[$i]}  AS sid{$i}";
        }

        try {
            $st = $pdoWorld->prepare("SELECT {$select} FROM {$table} WHERE {$idCol} IN ($ph)");
            $st->execute($enchantIds);

            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $eid = (int)($r['id'] ?? 0);
                if ($eid <= 0) continue;

                $effects = [];

                for ($i=1; $i<=3; $i++) {
                    // TC style
                    $eff  = (int)($r["eff{$i}"] ?? 0);
                    $arg  = (int)($r["arg{$i}"] ?? 0);
                    $min  = (int)($r["min{$i}"] ?? 0);
                    $max  = (int)($r["max{$i}"] ?? 0);
                    $amtTC = ($min !== 0) ? $min : $max;

                    // Old style
                    $type = (int)($r["type{$i}"] ?? 0);
                    $amtO = (int)($r["amt{$i}"] ?? 0);
                    $sid  = (int)($r["sid{$i}"] ?? 0);

                    $amount = 0;
                    $statId = 0;
                    $isStat = false;

                    // Prefer TC if present
                    if ($eff !== 0 && $amtTC !== 0 && $arg !== 0) {
                        $amount = $amtTC;
                        $statId = $arg;
                        $isStat = ($eff === 5 || $eff === 2);
                    } elseif ($type !== 0 && $amtO !== 0 && $sid !== 0) {
                        $amount = $amtO;
                        $statId = $sid;
                        $isStat = ($type === 5 || $type === 2);
                    }

                    if (!$isStat || $amount === 0 || $statId === 0) continue;

                    $label = armory_stat_label_from_type($statId);
                    if ($label === '') continue;

                    $effects[] = ['stat' => $label, 'amount' => $amount];
                }

                if ($effects) $out[$eid] = $effects;
            }

            if ($out) return $out;

        } catch (Throwable $e) {
            error_log("armory_fetch_enchant_stat_effects failed on {$table}: ".$e->getMessage());
        }
    }

    return $out;
}

function armory_add_to_map(array &$map, string $stat, int $amount): void {
    if ($stat === '' || $amount === 0) return;
    if (!isset($map[$stat])) $map[$stat] = 0;
    $map[$stat] += $amount;
}

function build_tooltip_html(PDO $pdoWorld, array $r, string $slotName, array $extra = []): string {
    $q   = (int)($r['quality'] ?? 1);
    $il  = (int)($r['itemlevel'] ?? 0);
    $name = (string)($r['name'] ?? 'Item');
    $clr  = get_quality_color_hex($q);

    $nameFormatted = parse_wow_color_codes($name);

    $h  = '<div class="armory-tt" style="border-color: '.$clr.';">';
    $h .= '<div class="tt-header">';
    $h .= '<div class="tt-name" style="color:'.$clr.'">'.$nameFormatted.'</div>';

    // Fallback sockets from item_template (always available)
    $socketsFallback = [];
    for ($i=1; $i<=3; $i++) {
        $c = 0;
        foreach (["socketcolor_{$i}", "socketcolor{$i}", "socketColor_{$i}", "socketColor{$i}"] as $k) {
            $lk = strtolower($k);
            if (isset($r[$lk])) { $c = (int)$r[$lk]; break; }
            if (isset($r[$k]))  { $c = (int)$r[$k]; break; }
        }
        if ($c > 0) $socketsFallback[] = $c;
    }

    // Use extras sockets if present, otherwise fallback
    $sockets = $extra['sockets'] ?? $socketsFallback;
    $gems    = $extra['gems']    ?? [];   // may be empty
    $enchant = $extra['enchant'] ?? null; // may be null
    $bonding  = (int)($r['bonding'] ?? 0);
    $bindText = '';
    if ($bonding === 1) $bindText = 'Binds when picked up';
    elseif ($bonding === 2) $bindText = 'Binds when equipped';
    elseif ($bonding === 3) $bindText = 'Binds when used';
    elseif ($bonding === 4) $bindText = 'Quest Item';

    if ($il > 0) {
        $h .= '<div class="tt-ilvl">Item Level '.$il.'</div>';
    }
    if ($bindText !== '') {
        $h .= '<div class="tt-bind">'.$bindText.'</div>';
    }

    if ((int)($r['maxcount'] ?? 0) === 1) {
        $h .= '<div class="tt-unique">Unique</div>';
    }

    if (!empty($extra['transmog']) && !empty($extra['transmog']['entry'])) {
        $tm = $extra['transmog'];
        $tmQ   = (int)($tm['quality'] ?? 1);
        $tmClr = get_quality_color_hex($tmQ);
        $tmName = parse_wow_color_codes((string)($tm['name'] ?? 'Transmog'));
        $h .= '<div class="tt-transmog">Transmogrified to: <span style="color:'.$tmClr.'">'.$tmName.'</span></div>';
    }

    $h .= '</div>';
    $h .= '<div class="tt-body">';

    $invType   = (int)($r['inventorytype'] ?? 0);
    $slotLabel = armory_get_inventory_type_name($invType);

    $itemClass = (int)($r['class'] ?? 0);
    $weaponTypeRight = '';
    if ($itemClass === 2) {
        $sub = (int)($r['subclass'] ?? 0);
        $weaponTypeRight = armory_get_weapon_subclass_name($sub);
    }

    if ($slotLabel || $weaponTypeRight) {
        $h .= '<div class="tt-row tt-slotrow">';
        $h .= '<div class="tt-slot-label">'.safe_html($slotLabel).'</div>';
        $h .= $weaponTypeRight !== '' ? '<div class="tt-slot-right">'.safe_html($weaponTypeRight).'</div>' : '<div class="tt-slot-right"></div>';
        $h .= '</div>';
    }

    if ((int)($r['armor'] ?? 0) > 0) $h .= '<div class="tt-armor">'.(int)$r['armor'].' Armor</div>';
    if ((int)($r['block'] ?? 0) > 0) $h .= '<div class="tt-armor">'.(int)$r['block'].' Block</div>';

    $min = (float)($r['dmg_min1'] ?? 0);
    $max = (float)($r['dmg_max1'] ?? 0);

    if ($min > 0 || $max > 0) {
        $delay = (float)($r['delay'] ?? 0);
        $speedText = '';
        $speed = 0;

        if ($delay > 0) {
            $speed = $delay / 1000;
            $speedText = 'Speed ' . number_format($speed, 2);
        }

        $h .= '<div class="tt-row tt-damagerow">';
        $h .= '<div class="tt-damage">'.(int)$min.' - '.(int)$max.' Damage</div>';
        $h .= $speedText !== '' ? '<div class="tt-speed tt-right">'.safe_html($speedText).'</div>' : '<div class="tt-speed tt-right"></div>';
        $h .= '</div>';

        if ($speed > 0) {
            $dps = round((($min + $max) / 2) / $speed, 1);
            if ($dps > 0) $h .= '<div class="tt-dps">('.$dps.' damage per second)</div>';
        }
    }

    $mainLines = [];
    $secLines  = [];

    for ($i = 1; $i <= 10; $i++) {
        $t = (int)($r['stat_type'.$i] ?? $r['stat_type_'.$i] ?? 0);
        $v = (int)($r['stat_value'.$i] ?? $r['stat_value_'.$i] ?? 0);
        if ($t <= 0 || $v <= 0) continue;

        $line = '';

        if ($t === 3)      $line = '<div class="tt-stat-primary">+'.$v.' Agility</div>';
        elseif ($t === 4)  $line = '<div class="tt-stat-primary">+'.$v.' Strength</div>';
        elseif ($t === 5)  $line = '<div class="tt-stat-primary">+'.$v.' Intellect</div>';
        elseif ($t === 6)  $line = '<div class="tt-stat-primary">+'.$v.' Spirit</div>';
        elseif ($t === 7)  $line = '<div class="tt-stat-primary">+'.$v.' Stamina</div>';

        elseif ($t === 31 || $t === 16 || $t === 17 || $t === 18) $line = '<div class="tt-stat-secondary">+'.$v.' Hit Rating</div>';
        elseif ($t === 32 || $t === 19 || $t === 20 || $t === 21) $line = '<div class="tt-stat-secondary">+'.$v.' Critical Strike Rating</div>';
        elseif ($t === 36) $line = '<div class="tt-stat-secondary">+'.$v.' Haste Rating</div>';
        elseif ($t === 37) $line = '<div class="tt-stat-secondary">+'.$v.' Expertise Rating</div>';
        elseif ($t === 38) $line = '<div class="tt-stat-secondary">+'.$v.' Attack Power</div>';
        elseif ($t === 39) $line = '<div class="tt-stat-secondary">+'.$v.' Ranged Attack Power</div>';
        elseif ($t === 45) $line = '<div class="tt-stat-secondary">+'.$v.' Spell Power</div>';
        elseif ($t === 35) $line = '<div class="tt-stat-secondary">+'.$v.' Resilience Rating</div>';
        elseif ($t === 12) $line = '<div class="tt-stat-secondary">+'.$v.' Defense Rating</div>';
        elseif ($t === 13) $line = '<div class="tt-stat-secondary">+'.$v.' Dodge Rating</div>';
        elseif ($t === 14) $line = '<div class="tt-stat-secondary">+'.$v.' Parry Rating</div>';
        elseif ($t === 15) $line = '<div class="tt-stat-secondary">+'.$v.' Block Rating</div>';
        elseif ($t === 44) $line = '<div class="tt-stat-secondary">+'.$v.' Armor Penetration Rating</div>';
        elseif ($t === 43) $line = '<div class="tt-stat-secondary">+'.$v.' Mana per 5 sec.</div>';
        elseif ($t === 47) $line = '<div class="tt-stat-secondary">+'.$v.' Spell Penetration</div>';

        if ($line === '') continue;

        if (in_array($t, [3,4,5,6,7], true)) $mainLines[] = $line;
        else $secLines[] = $line;
    }

    foreach ($mainLines as $ln) $h .= $ln;
    foreach ($secLines as $ln)  $h .= $ln;

    // ==============================
    // Enchants + Gems (Blizz-like)
    // ==============================
    if (!empty($extra['enchant']) || !empty($extra['gems'])) {
        $h .= '<div class="tt-divider"></div>';

        if (!empty($extra['enchant']['text'])) {
            $h .= '<div class="tt-enchant">Enchanted: <span class="tt-green">'.safe_html($extra['enchant']['text']).'</span></div>';
        }

        if (!empty($extra['gems']['list']) && is_array($extra['gems']['list'])) {
    foreach ($extra['gems']['list'] as $g) {
    $socketLabel = (string)($g['socketLabel'] ?? '');
    $gemText     = trim((string)($g['text'] ?? ''));
    $matches     = !empty($g['matches']);

    $socketColor = (int)($g['socketColor'] ?? 0);
    $gemEnchantId = (int)($g['enchantId'] ?? 0);
    $gemType      = (int)($g['gemType'] ?? 0);

    // decide which icon to show
    $iconName = '';
    if ($gemEnchantId > 0) {
        // filled socket -> blended gem icon (orange/purple/green etc)
        $iconName = armory_gem_type_icon_url($gemType);
        if ($iconName === '') {
            // fallback if type missing
            $iconName = armory_socket_icon_url($socketColor);
        }
    } else {
        // empty socket -> socket color icon
        $iconName = armory_socket_icon_url($socketColor);
    }

    $iconUrl = $iconName ? armory_icon_url_from_icon($iconName) : '';

    $cls = ($gemText !== '' ? ($matches ? 'tt-green' : 'tt-green tt-dim') : 'tt-gray');

    $h .= '<div class="tt-socket-line" style="display:flex;align-items:center;gap:6px;">';

    if ($iconUrl !== '') {
        $h .= '<img src="'.safe_html($iconUrl).'" alt="" style="width:14px;height:14px;display:block;">';
    }

    if ($gemText !== '') {
        $h .= '<span class="'.$cls.'">'.safe_html($gemText).'</span>';
    } else {
        $h .= '<span class="tt-gray">'.safe_html($socketLabel).'</span>';
    }

    $h .= '</div>';
}


    $sockBonus = trim((string)($extra['gems']['socketBonus'] ?? ''));
    if ($sockBonus !== '') {
        $active = !empty($extra['gems']['bonusActive']);
        $h .= '<div class="tt-socket-bonus">';
        $h .= 'Socket Bonus: <span class="'.($active ? 'tt-green' : 'tt-gray').'">'.parse_wow_color_codes_safe($sockBonus).'</span>';
        $h .= '</div>';
    }
}

    }

    $effectLines = armory_build_item_spell_lines($GLOBALS['pdoWorld'] ?? $pdoWorld, $r);
    if (!empty($effectLines)) {
        $h .= '<div class="tt-divider"></div>';
        foreach ($effectLines as $line) {
            $h .= '<div class="tt-effect">' . $line . '</div>';
        }
    }

    if (!empty($extra['set'])) {
        $set = $extra['set'];
        $h .= '<div class="tt-divider"></div>';
        $h .= '<div class="tt-set-name">'.parse_wow_color_codes($set['name']).' ('.$set['equipped'].'/'.$set['total'].')</div>';
        foreach ($set['bonuses'] as $bonus) {
            $bonusClass = $bonus['active'] ? 'tt-set-bonus-active' : 'tt-set-bonus';
            $bonusText = parse_wow_color_codes($bonus['text'] ?? 'Set bonus');
            $h .= '<div class="'.$bonusClass.'">('.$bonus['count'].') Set: '.$bonusText.'</div>';
        }
    }

    $maxDur    = (int)($r['maxdurability'] ?? 0);
    $reqLevel  = (int)($r['requiredlevel'] ?? 0);
    $allowClass = (int)($r['allowableclass'] ?? -1);

    if ($maxDur > 0 || $reqLevel > 1 || ($allowClass > 0 && $allowClass !== -1) || $il > 0) {
        $h .= '<div class="tt-divider"></div>';
        if ($maxDur > 0) $h .= '<div class="tt-durability">Durability '.$maxDur.' / '.$maxDur.'</div>';
        if ($reqLevel > 1) $h .= '<div class="tt-requires">Requires Level '.$reqLevel.'</div>';

        if ($allowClass > 0 && $allowClass !== -1) {
            $classNames = [1=>'Warrior', 2=>'Paladin', 4=>'Hunter', 8=>'Rogue', 16=>'Priest', 32=>'Death Knight', 64=>'Shaman', 128=>'Mage', 256=>'Warlock', 1024=>'Druid'];
            $classes = [];
            foreach ($classNames as $bit => $className) {
                if ($allowClass & $bit) $classes[] = $className;
            }
            if (count($classes) > 0 && count($classes) < 10) {
                $h .= '<div class="tt-requires">Classes: '.implode(', ', $classes).'</div>';
            }
        }
    }

    $description = trim((string)($r['description'] ?? ''));
    if ($description !== '') {
        $h .= '<div class="tt-divider"></div>';
        $h .= '<div class="tt-flavor">"'.parse_wow_color_codes($description).'"</div>';
    }

    $h .= '</div></div>';
    return $h;
}

function armory_spell_fetch_cached(PDO $pdoWorld, int $spellId, int $ttl = 86400 * 30): ?array {
    if ($spellId <= 0) return null;

    // cache hit
    try {
        $stmt = $pdoWorld->prepare("SELECT name, tooltip, updated_at FROM spell_tooltip_cache WHERE spell_id = ?");
        $stmt->execute([$spellId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $age = time() - (int)$row['updated_at'];
            if ($age < $ttl) {
                return ['name' => (string)$row['name'], 'tooltip' => (string)$row['tooltip']];
            }
        }
    } catch (Exception $e) {
    }

    $url = "https://www.wowhead.com/wotlk/spell=" . $spellId . "&xml";
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 2.0,
            'user_agent' => 'ThoriumArmory/1.0 (+armory spell cache)'
        ]
    ]);

    $xml = @file_get_contents($url, false, $ctx);
    if (!$xml) return $row ? ['name' => (string)$row['name'], 'tooltip' => (string)$row['tooltip']] : null;

    $name = '';
    $tooltip = '';

    if (preg_match('~<name><!\[CDATA\[(.*?)\]\]></name>~s', $xml, $m)) $name = trim($m[1]);
    if (preg_match('~<tooltip><!\[CDATA\[(.*?)\]\]></tooltip>~s', $xml, $m)) $tooltip = trim($m[1]);

    if ($name === '' && $tooltip === '') {
        return $row ? ['name' => (string)$row['name'], 'tooltip' => (string)$row['tooltip']] : null;
    }

    // store/update cache
    try {
        $stmt = $pdoWorld->prepare("
            INSERT INTO spell_tooltip_cache (spell_id, name, tooltip, updated_at)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE name=VALUES(name), tooltip=VALUES(tooltip), updated_at=VALUES(updated_at)
        ");
        $stmt->execute([$spellId, $name ?: ("Spell #".$spellId), $tooltip ?: '', time()]);
    } catch (Exception $e) {
    }

    return ['name' => $name ?: ("Spell #".$spellId), 'tooltip' => $tooltip ?: ''];
}


function armory_get_inventory_type_name(int $invType): string {
    $types = [
        1 => 'Head', 2 => 'Neck', 3 => 'Shoulder', 4 => 'Shirt', 5 => 'Chest',
        6 => 'Waist', 7 => 'Legs', 8 => 'Feet', 9 => 'Wrist', 10 => 'Hands',
        11 => 'Finger', 12 => 'Trinket', 13 => 'One-Hand', 14 => 'Off Hand',
        15 => 'Ranged', 16 => 'Back', 17 => 'Two-Hand', 18 => 'Bag',
        19 => 'Tabard', 20 => 'Chest', 21 => 'Main Hand', 22 => 'Off Hand',
        23 => 'Held In Off-hand', 24 => 'Ammo', 25 => 'Thrown', 26 => 'Ranged',
        28 => 'Relic'
    ];
    return $types[$invType] ?? '';
}

function armory_get_weapon_subclass_name(int $subclass): string {
    // TrinityCore / WotLK weapon subclasses (common ones)
    return [
        0  => 'Axe',        // 1H
        1  => 'Axe',        // 2H
        2  => 'Bow',
        3  => 'Gun',
        4  => 'Mace',       // 1H
        5  => 'Mace',       // 2H
        6  => 'Polearm',
        7  => 'Sword',      // 1H
        8  => 'Sword',      // 2H
        10 => 'Staff',
        13 => 'Fist Weapon',
        14 => 'Misc',
        15 => 'Dagger',
        16 => 'Thrown',
        18 => 'Crossbow',
        19 => 'Wand',
        20 => 'Fishing Pole',
    ][$subclass] ?? '';
}

function convert_rating_to_percent(string $ratingType, int $rating): float {
    $conversions = ['Hit Rating' => 26.23, 'Crit Rating' => 45.91, 'Haste Rating' => 32.79, 'Expertise Rating' => 8.20];
    return round($rating / ($conversions[$ratingType] ?? 1), 2);
}

function format_large_number(int $num): string {
    if ($num >= 1000000) return round($num / 1000000, 1) . 'M';
    elseif ($num >= 1000) return round($num / 1000, 1) . 'K';
    return (string)$num;
}

function parse_wow_color_codes(string $text): string {
    $text = preg_replace_callback('/\|c([0-9a-fA-F]{8})(.*?)(?:\|r|$)/s', function($matches) {
        $color = '#' . substr($matches[1], 2, 6);
        return '<span style="color: ' . $color . '">' . htmlspecialchars($matches[2]) . '</span>';
    }, $text);
    return str_replace('|r', '', $text);
}

function parse_wow_color_codes_safe(string $text): string {
    if ($text === '') return '';

    // Escape everything first (prevents HTML injection)
    $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

    // Now convert WoW color codes in the escaped string
    $escaped = preg_replace_callback('/\|c([0-9a-fA-F]{8})(.*?)(?:\|r|$)/s', function($m) {
        $color = '#' . substr($m[1], 2, 6);
        return '<span style="color: '.$color.'">'.$m[2].'</span>';
    }, $escaped);

    return str_replace(['|r','|R'], '', $escaped);
}

?>

<section class="armory-blizz">
    <div class="particles-container" id="particles"></div>

    <div class="armory-wrap">
        <div class="search-card">
            <form method="get" class="search-form">
                <div class="search-grid">
                    <div class="search-group">
                        <label>Character Name</label>
                        <input type="text" name="name" value="<?php echo safe_html($name); ?>" placeholder="Enter character name" required>
                    </div>
                    <div class="search-group">
                        <label>Realm</label>
                        <select name="realm">
                            <?php foreach ($realms as $id => $rname): ?>
                                <option value="<?php echo (int)$id; ?>"<?php if($id===$realm): ?> selected<?php endif; ?>><?php echo safe_html($rname); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn-sap">Search</button>
            </form>
        </div>

        <?php if ($name === ''): ?>
            <div class="empty-state">
                <div class="empty-icon">⚔</div>
                <h2>No Character Selected</h2>
                <p>Enter a character name to view their armory</p>
            </div>

        <?php elseif (!$char): ?>
            <div class="empty-state">
                <div class="empty-icon">✕</div>
                <h2>Character Not Found</h2>
                <p><strong><?php echo safe_html($name); ?></strong> does not exist on <strong><?php echo safe_html($realmName); ?></strong></p>
            </div>

        <?php else:
            $raceId = (int)$char['race'];
            $classId = (int)$char['class'];
            $genderId = (int)$char['gender'];
            $allianceRaces = [1, 3, 4, 7, 11];
            $faction = in_array($raceId, $allianceRaces) ? 'alliance' : 'horde';
            $factionIcon = '/assets/faction/' . $faction . '.png';

            $raceNames = [1 => 'human', 2 => 'orc', 3 => 'dwarf', 4 => 'nightelf', 5 => 'undead', 6 => 'tauren', 7 => 'gnome', 8 => 'troll', 10 => 'bloodelf', 11 => 'draenei'];
            $classNames = [1 => 'warrior', 2 => 'paladin', 3 => 'hunter', 4 => 'rogue', 5 => 'priest', 6 => 'deathknight', 7 => 'shaman', 8 => 'mage', 9 => 'warlock', 11 => 'druid'];
            $raceName = $raceNames[$raceId] ?? 'human';
            $className = $classNames[$classId] ?? 'warrior';
            $raceIcon = '/assets/race/' . ($genderId === 1 ? 'female' : '') . $raceName . '.png';
            $classIcon = '/assets/class/' . $className . '.png';
        ?>

<div class="char-header char-header--v2">
    <div class="char-head-left">
        <div class="char-head-nameRow">
            <img class="char-head-factionIcon" src="<?php echo safe_html($factionIcon); ?>" alt="<?php echo safe_html(ucfirst($faction)); ?>" onerror="this.style.display='none'"/>
            <div class="char-head-nameWrap">
                <h1 class="char-name" style="color: <?php echo $classColor; ?>;"><?php echo safe_html($char['name']); ?></h1>
                <?php if (!empty($guildName)): ?>
                    <div class="char-head-guild">&lt;<?php echo safe_html($guildName); ?>&gt;</div>
                <?php endif; ?>
            </div>
        </div>
        <div class="char-details">
            <span class="char-level">Level <?php echo (int)$char['level']; ?></span>
            <span class="char-sep">•</span>
            <span class="char-race"><?php echo safe_html(get_race_name($raceId)); ?></span>
            <span class="char-class"><?php echo safe_html(get_class_name($classId)); ?></span>
            <span class="char-sep">•</span>
            <span class="char-gender"><?php echo safe_html(get_gender_name($genderId)); ?></span>
            <span class="char-sep">•</span>
            <span class="char-realm"><?php echo safe_html($realmName); ?></span>
        </div>
    </div>

    <div class="char-head-mid">
        <div class="char-head-icons">
            <div class="char-icon-item">
                <div class="icon-frame race-frame">
                    <img src="<?php echo safe_html($raceIcon); ?>" alt="<?php echo safe_html(get_race_name($raceId)); ?>" onerror="this.style.display='none'">
                </div>
                <div class="icon-label"><?php echo safe_html(get_race_name($raceId)); ?></div>
            </div>
            <div class="char-icon-item">
                <div class="icon-frame-clean">
                    <img src="<?php echo safe_html($classIcon); ?>" alt="<?php echo safe_html(get_class_name($classId)); ?>" onerror="this.style.display='none'">
                </div>
                <div class="icon-label"><?php echo safe_html(get_class_name($classId)); ?></div>
            </div>
        </div>
        <?php if ($bloodmarkLevel > 0 || $artifactLevel > 0): ?>
            <div class="progression-badges progression-badges--header">
                <?php if ($bloodmarkLevel > 0): ?>
                    <div class="progression-badge bloodmark-badge">
                        <span class="badge-icon">🩸</span>
                        <div style="display:flex;flex-direction:column;line-height:1.2;">
                            <span class="badge-label">Bloodmark</span>
                            <span class="badge-value"><?php echo number_format($bloodmarkLevel); ?></span>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($artifactLevel > 0): ?>
                    <div class="progression-badge artifact-badge">
                        <span class="badge-icon">⚔️</span>
                        <div style="display:flex;flex-direction:column;line-height:1.2;">
                            <span class="badge-label">Artifact</span>
                            <span class="badge-value"><?php echo number_format($artifactLevel); ?></span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="char-head-right">
        <div class="char-scores">
            <div class="score-item">
                <div class="score-val"><?php echo $gearScore; ?></div>
                <div class="score-lbl">Gear Score</div>
            </div>
            <div class="score-item">
                <div class="score-val"><?php echo $avgIlvl; ?></div>
                <div class="score-lbl">Item Level</div>
            </div>
        </div>
    </div>
</div>

<?php
$talGuid   = (int)($char['guid'] ?? 0);
$talActive = (int)($char['activeTalentGroup'] ?? 0);
?>

<?php
// ===== Transmog map (unchanged) =====
$transmogMap = [];
try {
    $ci = armory_detect_inventory_schema($pdoChars);
    $ii = armory_detect_item_instance_schema($pdoChars);
    if ($ci && $ii) {
        $tmogStmt = $pdoChars->prepare("SELECT ci.{$ci['slot']} AS slot, ii.transmog FROM {$ci['table']} ci JOIN {$ii['table']} ii ON ii.{$ii['guid']} = ci.{$ci['item']} WHERE ci.{$ci['guid']} = ? AND ci.{$ci['bag']} = 0 AND ii.transmog > 0");
        $tmogStmt->execute([(int)$char['guid']]);
        while ($row = $tmogStmt->fetch(PDO::FETCH_ASSOC)) {
            $transmogMap[(int)$row['slot']] = (int)$row['transmog'];
        }
        if (!empty($transmogMap)) {
            $tmogItems = armory_fetch_items($pdoWorld, array_values($transmogMap));
            foreach ($tmogItems as $entry => $data) {
                $itemsById[$entry] = $data;
            }
        }
    }
} catch (Exception $e) {
    error_log("Transmog error: " . $e->getMessage());
}

/**
 * ============================
 * 3D VIEWER ITEM MAPPING (NEW CHAIN)
 * Priority per slot:
 *   1) Transmog displayId (if exists)
 *   2) Original item displayId (if exists)
 *   3) Hardcoded fallback displayId (safe defaults)
 *
 * JS will step through the chain ONLY if meta/item/<did> returns 404.
 * ============================
 */
$tcToViewerSlot = [0=>1, 1=>2, 2=>3, 3=>4, 4=>5, 5=>6, 6=>7, 7=>8, 8=>9, 9=>10, 10=>11, 11=>12, 12=>13, 13=>14, 14=>15, 18=>19];

$getDisplayId = function(int $entry) use (&$itemsById): int {
    if ($entry <= 0) return 0;
    $row = $itemsById[$entry] ?? null;
    if (!$row) return 0;
    $did = (int)armory_row_displayid($row);
    return $did > 0 ? $did : 0;
};

// IMPORTANT: fallback is based on the ORIGINAL item (not transmog)
$getFallbackDidForOriginal = function(int $originalEntry) use (&$itemsById): int {
    if ($originalEntry <= 0) return 0;
    $row = $itemsById[$originalEntry] ?? null;
    if (!$row) return 0;

    // Case-insensitive / variant key fetch
    $getInt = function(array $r, array $keys): int {
        foreach ($keys as $k) {
            if (isset($r[$k])) return (int)$r[$k];
            $lk = strtolower($k);
            foreach ($r as $rk => $rv) {
                if (strtolower((string)$rk) === $lk) return (int)$rv;
            }
        }
        return 0;
    };

    $class    = $getInt($row, ['class','itemclass','ItemClass']);
    $subclass = $getInt($row, ['subclass','itemsubclass','ItemSubClass']);
    $invType  = $getInt($row, ['inventorytype','inventory_type','InventoryType','invtype']);

    $fb = armory_get_safe_fallback_displayid($class, $subclass, $invType);
    return $fb > 0 ? $fb : 0;
};


// viewerSlot => [tmDid, origDid, fbDid]
$slotCandidates = [];

// Non-weapons
foreach (($slots ?? []) as $tcSlot => $originalEntry) {
    $tcSlot = (int)$tcSlot;
    $originalEntry = (int)$originalEntry;
    if ($originalEntry <= 0) continue;
    if (in_array($tcSlot, [15,16,17], true)) continue; // weapons handled below
    if (!isset($tcToViewerSlot[$tcSlot])) continue;

    $vslot = (int)$tcToViewerSlot[$tcSlot];

    $tmEntry = !empty($transmogMap[$tcSlot]) ? (int)$transmogMap[$tcSlot] : 0;

    $tmDid   = $tmEntry > 0 ? $getDisplayId($tmEntry) : 0;
    $origDid = $getDisplayId($originalEntry);
    $fbDid   = $getFallbackDidForOriginal($originalEntry);

    // Store chain (keep zeros; JS will skip them)
    $slotCandidates[$vslot] = [$tmDid, $origDid, $fbDid];
}

// Weapons
$isHunter = ((int)$classId === 3);

if ($isHunter) {
    $originalRanged = (int)($slots[17] ?? 0);
    if ($originalRanged > 0) {
        $tmEntry = !empty($transmogMap[17]) ? (int)$transmogMap[17] : 0;

        $tmDid   = $tmEntry > 0 ? $getDisplayId($tmEntry) : 0;
        $origDid = $getDisplayId($originalRanged);
        $fbDid   = $getFallbackDidForOriginal($originalRanged);

        // Viewer ranged slot for hunter
        $slotCandidates[23] = [$tmDid, $origDid, $fbDid];
    }
} else {
    $originalMH = (int)($slots[15] ?? 0);
    if ($originalMH > 0) {
        $tmEntry = !empty($transmogMap[15]) ? (int)$transmogMap[15] : 0;

        $tmDid   = $tmEntry > 0 ? $getDisplayId($tmEntry) : 0;
        $origDid = $getDisplayId($originalMH);
        $fbDid   = $getFallbackDidForOriginal($originalMH);

        // Viewer mainhand slot
        $slotCandidates[21] = [$tmDid, $origDid, $fbDid];
    }

    $originalOH = (int)($slots[16] ?? 0);
    if ($originalOH > 0) {
        $tmEntry = !empty($transmogMap[16]) ? (int)$transmogMap[16] : 0;

        $tmDid   = $tmEntry > 0 ? $getDisplayId($tmEntry) : 0;
        $origDid = $getDisplayId($originalOH);
        $fbDid   = $getFallbackDidForOriginal($originalOH);

        // Viewer offhand slot
        $slotCandidates[22] = [$tmDid, $origDid, $fbDid];
    }
}

$modelCharacter = [
    "race" => $raceId,
    "gender" => $genderId,
    "skin" => (int)($char["skin"] ?? 0),
    "face" => (int)($char["face"] ?? 0),
    "hairStyle" => (int)($char["hairStyle"] ?? 0),
    "hairColor" => (int)($char["hairColor"] ?? 0),
    "facialStyle" => (int)($char["facialStyle"] ?? 0),

    // slot => [tmDid, origDid, fbDid]
    "slotCandidates" => $slotCandidates,
];

?>

<div class="main-layout">
  <div class="left-col">
    <div class="paperdoll-card" id="armoryLeftPanel">

      <!-- Tabs header -->
      <div class="panel-head">
        <div class="panel-title">Character</div>

        <div class="panel-tabs" role="tablist" aria-label="Armory Panel Tabs">
          <button type="button" class="panel-tab is-active" data-tab="equipment" role="tab" aria-selected="true">
            Equipment
          </button>
          <button type="button" class="panel-tab" data-tab="talents" role="tab" aria-selected="false">
            Talents
          </button>
        </div>
      </div>

      <!-- Equipment panel -->
      <div id="panel-equipment" class="panel-body is-active" data-panel="equipment">
        <div class="paperdoll-grid">
          <?php
          $slotNames = get_slot_names();
          $equippedEntries = array_values(array_filter($slots));
          $setInfoCache = [];

          function render_slot($slot, $slots, $itemsById, $slotNames, $pdoWorld, $equippedEntries, &$setInfoCache, $transmogMap, $extrasBySlot) {
              $entry = (int)($slots[$slot] ?? 0);
              $row   = $entry ? ($itemsById[$entry] ?? null) : null;
              $q     = (int)($row['quality'] ?? 1);

              $icon = $row ? (string)($row['__icon_final'] ?? '') : '';
              if (!$icon && $row) {
                  $icon = armory_get_generic_icon(
                      (int)($row['inventorytype'] ?? 0),
                      (int)($row['class'] ?? 0),
                      (int)($row['subclass'] ?? 0)
                  );
              }

              $iurl     = $icon ? armory_icon_url_from_icon($icon) : '';
              $slotName = $slotNames[$slot] ?? 'Slot';

              if ($row) {
                  $extra = [];

                  // Transmog
                  $tmogEntry = (int)($transmogMap[$slot] ?? 0);
                  if ($tmogEntry > 0 && isset($itemsById[$tmogEntry])) {
                      $tmogRow = $itemsById[$tmogEntry];
                      $extra['transmog'] = [
                          'entry'   => $tmogEntry,
                          'name'    => (string)($tmogRow['name'] ?? ('Item #'.$tmogEntry)),
                          'quality' => (int)($tmogRow['quality'] ?? 1),
                          'icon'    => (string)($tmogRow['__icon_final'] ?? ''),
                      ];
                  }

                  // Set bonuses
                  $setId = (int)($row['itemset'] ?? 0);
                  if ($setId > 0) {
                      if (!isset($setInfoCache[$setId])) {
                          $setInfoCache[$setId] = armory_fetch_set_info($pdoWorld, $setId, $equippedEntries);
                      }
                      if (!empty($setInfoCache[$setId])) {
                          $extra['set'] = $setInfoCache[$setId];
                      }
                  }

                  // NEW: Gems + Enchants + Socket bonus computed earlier
                  if (!empty($extrasBySlot[$slot]) && is_array($extrasBySlot[$slot])) {
    $extra = array_merge($extra, $extrasBySlot[$slot]);
}

                  $ttHtml = build_tooltip_html($pdoWorld, $row, $slotName, $extra);
              } else {
                  $ttHtml = '<div class="armory-tt"><div class="tt-header"><div class="tt-empty">Empty slot</div></div></div>';
              }

              echo '<div class="pd-slot" data-tt="'.safe_html($ttHtml).'" data-entry="'.$entry.'">';
              echo '<div class="pd-icon '.get_quality_class($q).'">';

              if ($entry && $iurl) {
                  echo '<img src="'.safe_html($iurl).'" alt="" class="item-icon">';
              } else {
                  echo '<div class="pd-empty"></div>';
              }

              echo '</div></div>';
          }
          ?>

          <div class="pd-col pd-left">
            <?php
              render_slot(0,  $slots, $itemsById, $slotNames, $pdoWorld, $equippedEntries, $setInfoCache, $transmogMap, $extrasBySlot);
              render_slot(1,  $slots, $itemsById, $slotNames, $pdoWorld, $equippedEntries, $setInfoCache, $transmogMap, $extrasBySlot);
              render_slot(2,  $slots, $itemsById, $slotNames, $pdoWorld, $equippedEntries, $setInfoCache, $transmogMap, $extrasBySlot);
              render_slot(14, $slots, $itemsById, $slotNames, $pdoWorld, $equippedEntries, $setInfoCache, $transmogMap, $extrasBySlot);
              render_slot(4,  $slots, $itemsById, $slotNames, $pdoWorld, $equippedEntries, $setInfoCache, $transmogMap, $extrasBySlot);
              render_slot(18, $slots, $itemsById, $slotNames, $pdoWorld, $equippedEntries, $setInfoCache, $transmogMap, $extrasBySlot);
              render_slot(8,  $slots, $itemsById, $slotNames, $pdoWorld, $equippedEntries, $setInfoCache, $transmogMap, $extrasBySlot);
            ?>
          </div>

          <div class="pd-center">
            <div class="char-display char-display--compact">
              <div class="model3d-toolbar">
                <button type="button" id="toggleModel3d" class="btn-model-toggle" aria-pressed="true">
                  3D Model: <span class="state">ON</span>
                </button>
              </div>

              <div class="model3d-frame" data-enabled="1">
                <div id="model_3d" class="model3d" data-enabled="1"
                     style="--viewer-w:880px;--viewer-h:640px;--model-scale:.85;">
                  <div class="model3d__loading">Loading 3D model…</div>
                </div>

                <div id="model_3d_off" class="model3d-off">
                  <div class="model3d-off__inner">
                    <div class="model3d-off__icon">🧊</div>
                    <div class="model3d-off__title">3D Model Disabled</div>
                    <div class="model3d-off__text">Enable it if you want the character preview.</div>
                  </div>
                </div>
              </div>

              <?php
              $healthRaw  = (int)($char['health'] ?? 0);
              $stamina    = (int)($overallTotals['Stamina'] ?? 0);
              $maxHealth  = $stamina > 0 ? ($stamina * 10) + 1000 : ($healthRaw > 0 ? $healthRaw : 1000);

              $classPowerTypes = [1 => 1, 2 => 0, 3 => 0, 4 => 4, 5 => 0, 6 => 7, 7 => 0, 8 => 0, 9 => 0, 11 => 0];
              $powerType = $classPowerTypes[$classId] ?? 0;

              if ($powerType === 0) {
                  $intellect = (int)($overallTotals['Intellect'] ?? 0);
                  $maxPower  = $intellect > 0 ? ($intellect * 15) + 1000 : 1000;
              } else {
                  $maxPower = 100;
              }

              $powerNames = [0 => 'Mana', 1 => 'Rage', 3 => 'Focus', 4 => 'Energy', 7 => 'Runic Power'];
              $powerColors = [
                  0 => 'linear-gradient(90deg, #003d66, #005299, #0070bb)',
                  1 => 'linear-gradient(90deg, #990000, #cc0000, #e60000)',
                  3 => 'linear-gradient(90deg, #995200, #cc6600, #e67300)',
                  4 => 'linear-gradient(90deg, #999900, #cccc00, #e6e600)',
                  7 => 'linear-gradient(90deg, #006680, #0099cc, #00b3e6)'
              ];
              $powerName  = $powerNames[$powerType] ?? 'Mana';
              $powerColor = $powerColors[$powerType] ?? $powerColors[0];
              ?>

              <div class="char-bars">
                <div class="char-bar health-bar">
                  <div class="bar-fill health-fill" style="width: 100%"></div>
                  <div class="bar-label">HEALTH</div>
                  <div class="bar-value"><?php echo format_large_number($maxHealth); ?></div>
                </div>
                <div class="char-bar power-bar">
                  <div class="bar-fill power-fill" style="width: 100%; background: <?php echo $powerColor; ?>;"></div>
                  <div class="bar-label"><?php echo strtoupper($powerName); ?></div>
                  <div class="bar-value"><?php echo format_large_number($maxPower); ?></div>
                </div>
              </div>

            </div>
          </div>

          <div class="pd-col pd-right">
            <?php
              render_slot(9,  $slots, $itemsById, $slotNames, $pdoWorld, $equippedEntries, $setInfoCache, $transmogMap, $extrasBySlot);
              render_slot(5,  $slots, $itemsById, $slotNames, $pdoWorld, $equippedEntries, $setInfoCache, $transmogMap, $extrasBySlot);
              render_slot(6,  $slots, $itemsById, $slotNames, $pdoWorld, $equippedEntries, $setInfoCache, $transmogMap, $extrasBySlot);
              render_slot(7,  $slots, $itemsById, $slotNames, $pdoWorld, $equippedEntries, $setInfoCache, $transmogMap, $extrasBySlot);
              render_slot(10, $slots, $itemsById, $slotNames, $pdoWorld, $equippedEntries, $setInfoCache, $transmogMap, $extrasBySlot);
              render_slot(11, $slots, $itemsById, $slotNames, $pdoWorld, $equippedEntries, $setInfoCache, $transmogMap, $extrasBySlot);
              render_slot(12, $slots, $itemsById, $slotNames, $pdoWorld, $equippedEntries, $setInfoCache, $transmogMap, $extrasBySlot);
              render_slot(13, $slots, $itemsById, $slotNames, $pdoWorld, $equippedEntries, $setInfoCache, $transmogMap, $extrasBySlot);
            ?>
          </div>

          <div class="pd-weapons">
            <?php
              render_slot(15, $slots, $itemsById, $slotNames, $pdoWorld, $equippedEntries, $setInfoCache, $transmogMap, $extrasBySlot);
              render_slot(16, $slots, $itemsById, $slotNames, $pdoWorld, $equippedEntries, $setInfoCache, $transmogMap, $extrasBySlot);
              render_slot(17, $slots, $itemsById, $slotNames, $pdoWorld, $equippedEntries, $setInfoCache, $transmogMap, $extrasBySlot);
            ?>
          </div>

        </div>
      </div>

      <!-- Talents panel -->
      <div id="panel-talents" class="panel-body" data-panel="talents">
        <div class="talents-shell">
          <div id="talentsMount"
               data-guid="<?= (int)$talGuid ?>"
               data-active="<?= (int)$talActive ?>"
               data-api="/api/armory_talents_api.php">
            <div class="talents-loading">Loading talents…</div>
          </div>
          <script src="/assets/js/armory_talents.js?v=<?= time() ?>"></script>
        </div>
      </div>

    </div>
  </div>

  <!-- RIGHT COLUMN -->
  <div class="stats-col">
    <div class="stat-section">
      <div class="section-title">Character Statistics</div>
      <div class="stat-list">
        <div class="stat-section-header">Attributes</div>
        <?php
        $attrs = [
          'Strength'  => $overallTotals['Strength'] ?? 0,
          'Agility'   => $overallTotals['Agility'] ?? 0,
          'Stamina'   => $overallTotals['Stamina'] ?? 0,
          'Intellect' => $overallTotals['Intellect'] ?? 0,
          'Spirit'    => $overallTotals['Spirit'] ?? 0
        ];
        foreach ($attrs as $name => $val):
          if ((int)$val === 0) continue;
        ?>
		<div class="stat-row" data-stat="<?php echo safe_html($name); ?>">
		  <span class="stat-name"><?php echo safe_html($name); ?></span>

		  <strong class="stat-value">
			<?php echo (int)$val; ?>
			<span class="stat-info" aria-hidden="true">ⓘ</span>
		  </strong>
		</div>
        <?php endforeach; ?>

        <div class="stat-section-header">Combat Ratings</div>
<?php
$combat = [
  'Hit Rating'       => $overallTotals['Hit Rating'] ?? 0,
  'Crit Rating'      => $overallTotals['Crit Rating'] ?? 0,
  'Haste Rating'     => $overallTotals['Haste Rating'] ?? 0,
  'Expertise Rating' => $overallTotals['Expertise Rating'] ?? 0
];
foreach ($combat as $n => $rating):
  $rating = (int)$rating;
  if ($rating <= 0) continue;
  $percent = convert_rating_to_percent($n, $rating);
?>
  <div class="stat-row" data-stat="<?php echo safe_html($n); ?>">
    <span class="stat-name"><?php echo safe_html($n); ?></span>
    <strong class="stat-value">
      <?php echo $rating; ?>
      <span class="stat-pct">(<?php echo $percent; ?>%)</span>
      <span class="stat-info" aria-hidden="true">ⓘ</span>
    </strong>
  </div>
<?php endforeach; ?>


        <div class="stat-section-header">Power & Defense</div>
<?php
$power = [
  'Armor'          => $overallTotals['Armor'] ?? 0,
  'Attack Power'   => $overallTotals['Attack Power'] ?? 0,
  'Spell Power'    => $overallTotals['Spell Power'] ?? 0,
  'Defense Rating' => $overallTotals['Defense Rating'] ?? 0,
  'Resilience'     => $overallTotals['Resilience'] ?? 0
];
foreach ($power as $n => $val):
  $val = (int)$val;
  if ($val === 0) continue;
?>
  <div class="stat-row" data-stat="<?php echo safe_html($n); ?>">
    <span class="stat-name"><?php echo safe_html($n); ?></span>
    <strong class="stat-value">
      <?php echo $val; ?>
      <span class="stat-info" aria-hidden="true">ⓘ</span>
    </strong>
  </div>
<?php endforeach; ?>
      </div>
    </div>

    <div class="stat-section pvp-arena-section">
      <div class="section-title">PvP & Arena</div>
      <div class="stat-list">
        <?php
        $totalKills = (int)($char['totalKills'] ?? 0);
        $totalHonor = (int)($char['totalHonorPoints'] ?? 0);
        $arenaPoints = (int)($char['arenaPoints'] ?? 0);
        $arena2v2Rating = 0;
        $arena2v2Games  = 0;
        $arena3v3Rating = 0;
        $arena3v3Games  = 0;

        try {
          $stmt = $pdoChars->prepare("
            SELECT at.type, at.rating, atm.played_season
            FROM arena_team_member atm
            JOIN arena_team at ON atm.arenaTeamId = at.arenaTeamId
            WHERE atm.guid = ?
          ");
          $stmt->execute([(int)$char['guid']]);
          while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $type   = (int)$row['type'];
            $rating = (int)$row['rating'];
            $games  = (int)($row['played_season'] ?? 0);
            if ($type === 0) { $arena2v2Rating = $rating; $arena2v2Games = $games; }
            elseif ($type === 1) { $arena3v3Rating = $rating; $arena3v3Games = $games; }
          }
        } catch (Exception $e) {
          error_log("Arena rating fetch error: " . $e->getMessage());
        }
        ?>

        <div class="stat-section-header">PvP Statistics</div>
        <div class="stat-row">
          <span>Honorable Kills</span>
          <strong class="pvp-value"><?php echo number_format($totalKills); ?></strong>
        </div>
        <div class="stat-row">
          <span>Honor Points</span>
          <strong class="pvp-value"><?php echo number_format($totalHonor); ?></strong>
        </div>
        <?php if ($arenaPoints > 0): ?>
          <div class="stat-row">
            <span>Arena Points</span>
            <strong class="pvp-value"><?php echo number_format($arenaPoints); ?></strong>
          </div>
        <?php endif; ?>

        <div class="stat-section-header">Arena Ratings</div>
        <div class="stat-row">
          <span>2v2 Rating</span>
          <strong class="arena-value"><?php echo $arena2v2Rating > 0 ? $arena2v2Rating : 'Unrated'; ?></strong>
        </div>
        <?php if ($arena2v2Games > 0): ?>
          <div class="stat-row stat-row-sub">
            <span>2v2 Games</span>
            <strong class="arena-value-dim"><?php echo number_format($arena2v2Games); ?></strong>
          </div>
        <?php endif; ?>

        <div class="stat-row">
          <span>3v3 Rating</span>
          <strong class="arena-value"><?php echo $arena3v3Rating > 0 ? $arena3v3Rating : 'Unrated'; ?></strong>
        </div>
        <?php if ($arena3v3Games > 0): ?>
          <div class="stat-row stat-row-sub">
            <span>3v3 Games</span>
            <strong class="arena-value-dim"><?php echo number_format($arena3v3Games); ?></strong>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="stat-section prof-section">
      <div class="section-title">Professions</div>
      <div class="stat-list">
        <?php if (empty($professions['primary']) && empty($professions['secondary'])): ?>
          <div class="stat-row">
            <span>No professions found</span>
            <strong class="prof-value">—</strong>
          </div>
        <?php else: ?>
          <?php if (!empty($professions['primary'])): ?>
            <div class="prof-section-title">Primary</div>
            <?php foreach ($professions['primary'] as $p): ?>
              <div class="prof-row">
                <div class="prof-left">
                  <div class="prof-name"><?php echo safe_html($p['name']); ?></div>
                  <div class="prof-bar">
                    <div class="prof-bar-fill" style="width: <?php echo (int)$p['pct']; ?>%"></div>
                  </div>
                </div>
                <div class="prof-right">
                  <div class="prof-level"><?php echo (int)$p['value']; ?>/<?php echo (int)$p['max']; ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>

          <?php if (!empty($professions['secondary'])): ?>
            <div class="prof-section-title" style="margin-top: 0.75rem;">Secondary</div>
            <?php foreach ($professions['secondary'] as $p): ?>
              <div class="prof-row">
                <div class="prof-left">
                  <div class="prof-name"><?php echo safe_html($p['name']); ?></div>
                  <div class="prof-bar prof-bar--dim">
                    <div class="prof-bar-fill" style="width: <?php echo (int)$p['pct']; ?>%"></div>
                  </div>
                </div>
                <div class="prof-right">
                  <div class="prof-level"><?php echo (int)$p['value']; ?>/<?php echo (int)$p['max']; ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php endif; ?>

    </div>
</section>
<link rel="stylesheet" href="/assets/css/armory.css?v=<?php echo filemtime(__DIR__ . '/../../public/assets/css/armory.css'); ?>">
<script>
(function() {
    'use strict';

    class ArmoryBlizz {
        constructor() {
            this.tooltip = this.createTooltip();
            this.init();
            this.createParticles();
        }

        createTooltip() {
            const wrap = document.createElement('div');
            wrap.className = 'tt-wrap';
            document.body.appendChild(wrap);
            return wrap;
        }

        init() {
  const root = document.querySelector('.armory-wrap');
  if (!root) return;

  const onOver = (e) => {
    const el = e.target.closest('[data-tt]');
    if (!el) return;

    // If we're moving inside the same element, ignore
    const from = e.relatedTarget;
    if (from && el.contains(from)) return;

    this.show(e, el);
  };

  const onOut = (e) => {
    const el = e.target.closest('[data-tt]');
    if (!el) return;

    // If we're moving to a child inside the same element, ignore
    const to = e.relatedTarget;
    if (to && el.contains(to)) return;

    this.hide();
  };

  root.addEventListener('mouseover', onOver, true);
  root.addEventListener('mouseout', onOut, true);

  root.addEventListener('mousemove', (e) => {
    if (this.tooltip.style.opacity === '0') return;
    this.position(e);
  }, true);
}



        show(e, el) {
  const b64 = el.getAttribute('data-tt');
  if (!b64) return;

  let html = '';
  try {
    html = atob(b64);
  } catch (err) {
    html = b64;
  }

  this.tooltip.innerHTML = html;
  this.tooltip.classList.add('tt-visible');
  this.tooltip.style.opacity = '1';
  this.position(e);
}

hide() {
  this.tooltip.classList.remove('tt-visible');
  this.tooltip.style.opacity = '0';
}

        move(e) {
            if (this.tooltip.style.opacity === '0') return;
            this.position(e);
        }

        position(e) {
            const rect = this.tooltip.getBoundingClientRect();
            const offset = 15;
            const padding = 20;

            let x = e.clientX + offset;
            let y = e.clientY + offset;

            if (x + rect.width > window.innerWidth - padding) {
                x = e.clientX - rect.width - offset;
            }
            if (y + rect.height > window.innerHeight - padding) {
                y = e.clientY - rect.height - offset;
            }

            x = Math.max(padding, Math.min(x, window.innerWidth - rect.width - padding));
            y = Math.max(padding, Math.min(y, window.innerHeight - rect.height - padding));

            this.tooltip.style.left = `${x}px`;
            this.tooltip.style.top = `${y}px`;
        }

        createParticles() {
            const container = document.getElementById('particles');
            if (!container) return;

            // Reduced particle count from 75 to 50 for better performance
            for (let i = 0; i < 0; i++) {
                const particle = document.createElement('div');
                particle.className = 'particle';

                const size = Math.random() * 2.5 + 0.5;
                particle.style.width = `${size}px`;
                particle.style.height = `${size}px`;
                particle.style.left = `${Math.random() * 100}%`;
                particle.style.top = `${Math.random() * 100}%`;

                const duration = Math.random() * 30 + 15;
                const driftX = (Math.random() - 0.5) * 300;
                const driftY = (Math.random() - 0.5) * 300;
                const rotation = Math.random() * 360;

                particle.style.animation = `floatDust ${duration}s infinite ease-in-out`;
                particle.style.animationDelay = `${Math.random() * -20}s`;
                particle.style.setProperty('--drift-x', `${driftX}px`);
                particle.style.setProperty('--drift-y', `${driftY}px`);
                particle.style.setProperty('--rotation', `${rotation}deg`);

                container.appendChild(particle);
            }

            const style = document.createElement('style');
            style.textContent = `
                @keyframes floatDust {
                    0%, 100% { transform: translate(0, 0) rotate(0deg); opacity: 0; }
                    5%, 95% { opacity: 0.4; }
                    50% { transform: translate(var(--drift-x), var(--drift-y)) rotate(var(--rotation)); opacity: 0.5; }
                }
            `;
            document.head.appendChild(style);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => new ArmoryBlizz());
    } else {
        new ArmoryBlizz();
    }
})();
</script>

<script src="/assets/jquery.min.js"></script>
<script>
  if (!jQuery.isArray) jQuery.isArray = Array.isArray;
  if (window.$ && !$.isArray) $.isArray = Array.isArray;
</script>
<script src="/assets/viewer.min.js"></script>
<script src="/assets/wow_model_viewer.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'].'/assets/wow_model_viewer.js') ?>"></script>

<script>
(function() {
  const originalOpen = XMLHttpRequest.prototype.open;
  const originalSend = XMLHttpRequest.prototype.send;

  const failedDisplayIds = new Set();

  function getUpstreamFromProxy(url) {
    if (!url || typeof url !== "string") return "";
    try {
      if (url.indexOf("/model-proxy.php") !== -1) {
        const u = new URL(url, window.location.origin);
        const upstream = u.searchParams.get("url") || "";
        return upstream;
      }
    } catch (e) {}
    return url;
  }

  function extractMetaDid(upstreamUrl) {
  if (!upstreamUrl) return 0;
  
  // Match various patterns - ORDER MATTERS!
  const patterns = [
    // armor/weapon URLs: /meta/armor/{slot}/{displayId}.json
    /\/meta\/armor\/\d+\/(\d+)(?:\.json)?\b/i,
    /\/meta\/weapon\/\d+\/(\d+)(?:\.json)?\b/i,
    // item URLs: /meta/item/{displayId}.json
    /\/meta\/item\/(\d+)(?:\.json)?\b/i,
    // fallback: last number before .json
    /\/(\d+)\.json$/i
  ];
  
  for (const pattern of patterns) {
    const m = upstreamUrl.match(pattern);
    if (m) {
      const did = parseInt(m[1], 10);
      console.log(`[extractMetaDid] URL: ${upstreamUrl} => DID: ${did}`);
      return did;
    }
  }
  
  console.warn(`[extractMetaDid] No match for URL: ${upstreamUrl}`);
  return 0;
}

  XMLHttpRequest.prototype.open = function(method, url) {
    this.__armory_raw_url = url;
    const upstream = getUpstreamFromProxy(url);
    this.__armory_upstream_url = upstream;

    if (typeof url === "string" && url.indexOf("wow.zamimg.com") !== -1) {
      url = "/model-proxy.php?url=" + encodeURIComponent(url);
    }

    return originalOpen.apply(this, arguments.length ? [method, url].concat([].slice.call(arguments, 2)) : arguments);
  };

  XMLHttpRequest.prototype.send = function() {
  const upstream = this.__armory_upstream_url || getUpstreamFromProxy(this.__armory_raw_url || "");
  const isModelViewerRequest = typeof upstream === "string"
    && upstream.indexOf("wow.zamimg.com") !== -1
    && /\/modelviewer\/(live|classic)\//i.test(upstream);

  if (isModelViewerRequest) {
    const did = extractMetaDid(upstream);
    
    this.addEventListener("load", function() {
      try {
        const extractedDid = did || extractMetaDid(this.__armory_upstream_url || upstream || "");
        if (!extractedDid) return;
        
        // Mark as failed if: 404, 5xx, or empty response
        if (this.status === 404 || this.status >= 500) {
          console.log(`[Armory] Failed displayId ${extractedDid} (HTTP ${this.status})`);
          failedDisplayIds.add(extractedDid);
        } else if (this.status >= 200 && this.status < 300) {
          // Check if response is valid
          try {
            const text = this.responseText || "";
            if (text.trim() === "" || text === "null" || text === "{}") {
              console.log(`[Armory] Failed displayId ${extractedDid} (empty response)`);
              failedDisplayIds.add(extractedDid);
            }
          } catch (e) {}
        }
      } catch (e) {
        console.error("[Armory] XHR load error:", e);
      }
    });

    this.addEventListener("error", () => {
      try {
        const extractedDid = did || extractMetaDid(upstream || "");
        if (extractedDid) {
          console.log(`[Armory] Failed displayId ${extractedDid} (network error)`);
          failedDisplayIds.add(extractedDid);
        }
      } catch (e) {}
    });

    this.addEventListener("abort", () => {
      try {
        const extractedDid = did || extractMetaDid(upstream || "");
        if (extractedDid) {
          console.log(`[Armory] Failed displayId ${extractedDid} (aborted)`);
          failedDisplayIds.add(extractedDid);
        }
      } catch (e) {}
    });
  }

  return originalSend.apply(this, arguments);
};

  
  function isMetaEligibleDid(did) {
  return did > 0 && did < 2000000;
}


  window.__ARMORY_FAILED_DIDS__ = failedDisplayIds;

  window.WH = window.WH || {};
  window.WH.WebP = window.WH.WebP || {
    supported: false,
    getImageExtension: function() { return this.supported ? ".webp" : ".png"; }
  };
  
  const MODEL_PREF_KEY = "armory_model3d_enabled";

function getModelPref() {
  const v = localStorage.getItem(MODEL_PREF_KEY);
  if (v === null) return true;
  return v === "1";
}
function setModelPref(enabled) {
  localStorage.setItem(MODEL_PREF_KEY, enabled ? "1" : "0");
}

function teardownViewer(root) {
  if (!root) return;

  if (root.__armoryResizeHandler) {
    window.removeEventListener("resize", root.__armoryResizeHandler);
    root.__armoryResizeHandler = null;
  }

  if (root.__armoryObserver) {
    try { root.__armoryObserver.disconnect(); } catch (e) {}
    root.__armoryObserver = null;
  }

  // Clear flags
  root.__animationSet = false;
  root.__printedAnims = false;

  root.querySelectorAll("canvas").forEach(c => c.remove());

  const loading = root.querySelector(".model3d__loading");
  root.innerHTML = "";
  if (loading) root.appendChild(loading);
}

  function bootModel() {
  const root = document.getElementById('model_3d');
  const offBox = document.getElementById('model_3d_off');
  const btn = document.getElementById('toggleModel3d');

  if (!root) return;

  // UI state
  function paintToggle(enabled) {
    if (btn) {
      btn.setAttribute("aria-pressed", enabled ? "true" : "false");
      const s = btn.querySelector(".state");
      if (s) s.textContent = enabled ? "ON" : "OFF";
    }
    root.style.display = enabled ? "" : "none";
    if (offBox) offBox.style.display = enabled ? "none" : "";
    root.dataset.enabled = enabled ? "1" : "0";
  }

  // Initial preference
  let enabled = getModelPref();
  paintToggle(enabled);

  // Click handler
  if (btn && !btn.__bound) {
    btn.__bound = true;
    btn.addEventListener("click", () => {
      enabled = !enabled;
      setModelPref(enabled);
      paintToggle(enabled);

      if (!enabled) {
        teardownViewer(root);
        return;
      }

      // Recreate loading node
      if (!root.querySelector(".model3d__loading")) {
        const d = document.createElement("div");
        d.className = "model3d__loading";
        d.textContent = "Loading 3D model…";
        root.appendChild(d);
      }

      // Boot viewer again
      initBoot();
    });
  }

  // If disabled, do NOT init the viewer at all.
  if (!enabled) return;

  // Boot normally
  initBoot();

  function initBoot() {
    if (!window.jQuery || !window.WowModelViewer) return;

    const character = <?php echo json_encode($modelCharacter ?? null, JSON_UNESCAPED_SLASHES); ?>;
    if (!character || !character.race) return;

    const loading = root.querySelector('.model3d__loading');
    let viewerInitialized = false;

    // LAZY LOAD: Only initialize when viewer is scrolled into view
    const observer = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (!getModelPref()) return;
        if (entry.isIntersecting && !viewerInitialized) {
          viewerInitialized = true;
          initViewer();
          observer.disconnect();
        }
      });
    }, { rootMargin: '200px', threshold: 0.1 });

    root.__armoryObserver = observer;
    observer.observe(root);

    function applyCanvasClamp(root, maxDpr) {
      const canvas = root && root.querySelector && root.querySelector("canvas");
      if (!canvas) return false;

      const cssW = Math.max(1, Math.floor(root.clientWidth));
      const cssH = Math.max(1, Math.floor(root.clientHeight));
      const dpr  = Math.min(window.devicePixelRatio || 1, maxDpr || 1.25);

      canvas.style.width  = cssW + "px";
      canvas.style.height = cssH + "px";

      const targetW = Math.max(1, Math.floor(cssW * dpr));
      const targetH = Math.max(1, Math.floor(cssH * dpr));

      if (canvas.width !== targetW)  canvas.width  = targetW;
      if (canvas.height !== targetH) canvas.height = targetH;

      canvas.style.imageRendering = "auto";
      return true;
    }

    function debounce(fn, ms) {
      let t = 0;
      return function() {
        clearTimeout(t);
        t = setTimeout(() => fn.apply(this, arguments), ms);
      };
    }

    function initViewer() {
      if (!getModelPref()) return;
      if (loading) loading.style.display = 'grid';

      const candidates = character.slotCandidates || {};
      const modelId = (character.race - 1) * 2 + 1 + character.gender;

      let didRetryCount = 0;
      const maxRetries = 1;

function pickDidForSlot(slot, chain) {
  const failed = window.__ARMORY_FAILED_DIDS__ || new Set();
  if (!Array.isArray(chain)) return 0;

  console.log(`[Armory] Picking DID for slot ${slot}, chain:`, chain, 'failed:', Array.from(failed));

  for (let i = 0; i < chain.length; i++) {
    const did = parseInt(chain[i], 10) || 0;
    if (!did) continue;
    if (!isMetaEligibleDid(did)) {
      console.log(`  - Skipping ${did} (not eligible)`);
      continue;
    }
    if (failed.has(did)) {
      console.log(`  - Skipping ${did} (failed)`);
      continue;
    }
    console.log(`  - Using ${did} for slot ${slot}`);
    return did;
  }

  console.log(`  - No valid DID found for slot ${slot}`);
  return 0;
}

      function buildItemsFromCandidates() {
        const out = [];
        const slots = Object.keys(candidates);
        for (let i = 0; i < slots.length; i++) {
          const slot = parseInt(slots[i], 10);
          if (!slot) continue;
          const chain = candidates[slot];
          const did = pickDidForSlot(slot, chain);
          if (did) out.push([slot, did]);
        }
        out.sort((a,b) => a[0] - b[0]);
        return out;
      }

      // Replace anyUsedDidFailed with this:
function shouldRetry() {
  const failed = window.__ARMORY_FAILED_DIDS__ || new Set();
  if (failed.size === 0) return false;
  
  // Check if any slot has a failed primary/transmog that could fall back
  const slots = Object.keys(candidates);
  for (let i = 0; i < slots.length; i++) {
    const slot = parseInt(slots[i], 10);
    if (!slot) continue;
    
    const chain = candidates[slot];
    if (!Array.isArray(chain) || chain.length < 2) continue;
    
    // Check if the first choice failed and we have alternatives
    const firstChoice = parseInt(chain[0], 10) || 0;
    if (firstChoice > 0 && failed.has(firstChoice)) {
      // We have a failed first choice - check if there are valid alternatives
      for (let j = 1; j < chain.length; j++) {
        const alt = parseInt(chain[j], 10) || 0;
        if (alt > 0 && isMetaEligibleDid(alt) && !failed.has(alt)) {
          console.log(`[Armory] Slot ${slot} can retry: ${firstChoice} failed, trying ${alt}`);
          return true;
        }
      }
    }
  }
  
  return false;
}

function mountViewer(contentPath, items) {
  window.CONTENT_PATH = contentPath;

  const viewerOptions = {
    type: 2,
    container: jQuery(root),
    aspect: root.clientWidth / root.clientHeight,
    contentPath: window.CONTENT_PATH,
    items: items,
    models: [{
      type: 16,
      id: modelId,
      skin: character.skin,
      face: character.face,
      hairStyle: character.hairStyle,
      hairColor: character.hairColor,
      facialHair: character.facialStyle,
      items: items
    }]
  };

  try {
    viewerOptions.enableCameraAnimation = false;
    viewerOptions.antialias = false;
    viewerOptions.shadows = false;
  } catch (e) {}

  const wmv = new window.WowModelViewer(viewerOptions);
  root.__wmv = wmv;

  const MAX_DPR = 1.0;
  const onResize = debounce(() => applyCanvasClamp(root, MAX_DPR), 150);
  root.__armoryResizeHandler = onResize;

  const obs = new MutationObserver(() => {
  if (!getModelPref()) return;
  const hasCanvas = !!root.querySelector('canvas');
  if (!hasCanvas) return;

  if (!root.__animationSet) {
    root.__animationSet = true;
    
    const trySetAnimation = () => {
      try {
        const model = root.__wmv?.model || root.__wmv?.viewer || root.__wmv;
        if (!model || !model.setAnimation) return false;
        
        // Try animations known to loop smoothly
        const smoothAnimations = [
          "Idle",           // Usually loops better than Stand
          "ReadyUnarmed",   // Breathing idle
          "Stand"           // Fallback
        ];
        
        for (const anim of smoothAnimations) {
          try {
            if (model.setAnimPaused) model.setAnimPaused(false);
            
            // Try to enable looping if the method exists
            if (model.setAnimationLoop) model.setAnimationLoop(true);
            
            model.setAnimation(anim);
            console.log(`[Armory] ✅ Set animation: ${anim}`);
            return true;
          } catch (e) {
            // Animation doesn't exist, try next
            continue;
          }
        }
        
        return false;
      } catch (e) {
        return false;
      }
    };
    
    // Try multiple times as model loads
    trySetAnimation();
    setTimeout(trySetAnimation, 500);
    setTimeout(trySetAnimation, 1500);
  }

  if (loading) loading.style.display = 'none';
  applyCanvasClamp(root, MAX_DPR);

  window.removeEventListener("resize", onResize);
  window.addEventListener("resize", onResize, { passive: true });

  obs.disconnect();
});

  obs.observe(root, { childList: true, subtree: true });

  setTimeout(() => {
    if (loading) loading.style.display = 'none';
    obs.disconnect();
  }, 3000);
}

function remount(contentPath) {
  if (!getModelPref()) return;

  teardownViewer(root);

  const items = buildItemsFromCandidates();
  mountViewer(contentPath, items);

  // ✅ NO RETRY LOGIC HERE - moved to scheduleRetryCheck()
}

// After the model loads, aggressively try to set Idle animation
const forceIdleAnimation = () => {
  try {
    // Access the viewer instance
    const viewer = root.__wmv;
    if (!viewer) return false;
    
    // Try to set Idle animation directly on the underlying model
    if (viewer.renderer && viewer.renderer.models && viewer.renderer.models[0]) {
      const model = viewer.renderer.models[0];
      
      // Check if Idle animation exists
      const animations = model.ap ? model.ap.map(a => a.j) : [];
      console.log('[Armory] Available animations:', animations);
      
      if (animations.includes('Idle')) {
        model.setAnimation('Idle');
        console.log('[Armory] ✅ Switched to Idle animation');
        return true;
      } else if (animations.includes('ReadyUnarmed')) {
        model.setAnimation('ReadyUnarmed');
        console.log('[Armory] ✅ Switched to ReadyUnarmed animation');
        return true;
      }
    }
    
    // Fallback: try via wrapper methods
    if (viewer.setAnimation) {
      viewer.setAnimation('Idle');
      console.log('[Armory] ✅ Set Idle via wrapper');
      return true;
    }
  } catch (e) {
    console.log('[Armory] Animation switch failed:', e.message);
  }
  return false;
};

// Try repeatedly until it works
let attempts = 0;
const tryInterval = setInterval(() => {
  if (forceIdleAnimation() || attempts++ > 20) {
    clearInterval(tryInterval);
  }
}, 300);

function scheduleRetryCheck(contentPath) {
  let checksCount = 0;
  const maxChecks = 20;
  const checkInterval = 500;
  
  const intervalId = setInterval(() => {
    checksCount++;
    
    const failed = window.__ARMORY_FAILED_DIDS__ || new Set();
    const hasFailures = failed.size > 0;
    const timeExpired = checksCount >= maxChecks;
    
    // Don't log every check - reduces console spam
    if (checksCount === 10) {
      console.log(`[Armory] Still waiting for XHRs... (${failed.size} failures so far)`);
    }
    
    if (!hasFailures && !timeExpired) {
      return;
    }
    
    clearInterval(intervalId);
    
    console.log(`[Armory] Final check after ${checksCount * checkInterval}ms, failed DIDs:`, Array.from(failed));
    
    // ✅ ONLY RETRY ONCE (not 3 times)
    if (didRetryCount >= 1) { // Changed from 3 to 1
      console.log('[Armory] Max retry reached - displaying with available items');
      return;
    }
    
    if (!shouldRetry()) {
      console.log('[Armory] No retry needed');
      return;
    }
    
    console.log(`[Armory] Rebuilding once with fallbacks...`);

// Hide model during rebuild to prevent jank
const modelContainer = document.querySelector('.character-model-container');
if (modelContainer) {
  modelContainer.style.opacity = '0';
  modelContainer.style.transition = 'opacity 0.3s';
}

didRetryCount++;
remount(contentPath);

// Fade back in after assets settle
setTimeout(() => {
  if (modelContainer) {
    modelContainer.style.opacity = '1';
  }
}, 2000);

scheduleRetryCheck(contentPath);
  }, checkInterval);
}

try {
  remount('https://wow.zamimg.com/modelviewer/live/');
  
  // ✅ Schedule the FIRST retry check after initial mount
  scheduleRetryCheck('https://wow.zamimg.com/modelviewer/live/');
} catch (e) {
  try {
    didRetryCount = 0;
    remount('https://wow.zamimg.com/modelviewer/classic/');
    scheduleRetryCheck('https://wow.zamimg.com/modelviewer/classic/');
  } catch (e2) {
    if (loading) loading.innerHTML = '<div style="color:#ff6b6b;">Failed to load 3D model</div>';
  }
}
    }
  }
}


  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootModel);
  } else {
    bootModel();
  }
})();
</script>
<script>
(() => {
  const params = new URLSearchParams(location.search);
  const DEBUG_JANK = params.get('debug_jank') === '1';

  if (!DEBUG_JANK) return;

  // Long Task API (Chrome/Chromium-based browsers)
  if ('PerformanceObserver' in window) {
    try {
      const po = new PerformanceObserver((list) => {
        for (const e of list.getEntries()) {
          const a = (e.attribution && e.attribution[0]) || {};
          console.warn('[JANK][longtask]', {
            duration: Math.round(e.duration) + 'ms',
            name: e.name,
            startTime: Math.round(e.startTime) + 'ms',
            culprit: a.name || a.containerName || 'unknown',
            containerType: a.containerType || 'unknown',
            containerSrc: a.containerSrc || ''
          });
        }
      });
      po.observe({ entryTypes: ['longtask'] });
    } catch (e) {}
  }

  // Frame hitch tracker
  let last = performance.now();
  function tick(now) {
    const dt = now - last;
    if (dt > 100) console.warn('[JANK][frame]', Math.round(dt) + 'ms');
    else if (dt > 34) console.log('[jank frame]', Math.round(dt) + 'ms');
    last = now;
    requestAnimationFrame(tick);
  }
  requestAnimationFrame(tick);
})();
</script>
<script>
(() => {
  const card = document.getElementById('armoryLeftPanel');
  if (!card) return;

  const tabs = card.querySelectorAll('.panel-tab');
  const panels = card.querySelectorAll('.panel-body[data-panel]');
  const KEY = 'armory_leftpanel_tab';

  function setTab(name) {
    tabs.forEach(b => {
      const on = b.dataset.tab === name;
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    panels.forEach(p => p.classList.toggle('is-active', p.dataset.panel === name));
    try { localStorage.setItem(KEY, name); } catch(e) {}
  }

  tabs.forEach(btn => btn.addEventListener('click', () => setTab(btn.dataset.tab)));

  let initial = 'equipment';
  try {
    const saved = localStorage.getItem(KEY);
    if (saved === 'talents' || saved === 'equipment') initial = saved;
  } catch(e) {}

  setTab(initial);
})();
</script>
<script>
window.ARMORY_STAT_BREAKDOWN = <?=
  json_encode($statBreakdowns, JSON_UNESCAPED_SLASHES);
?>;
</script>

<script>
(() => {
  function buildStatBreakdowns() {
    const data = window.ARMORY_STAT_BREAKDOWN || {};
    document.querySelectorAll('.stat-row[data-stat]').forEach(row => {
      const stat = row.dataset.stat;
      const d = data[stat];
      if (!d) return;

      // prevent duplicates
      if (row.querySelector('.stat-breakdown')) return;

      const box = document.createElement('div');
      box.className = 'stat-breakdown';
      box.dataset.stat = stat; // ✅ ADD THIS LINE
      box.innerHTML = `
  <h4>${stat}</h4>

  ${d.gear ? `<div class="line"><span>Gear</span><span>+${d.gear}</span></div>` : ``}
  ${d.enchants ? `<div class="line"><span>Enchants</span><span>+${d.enchants}</span></div>` : ``}
  ${d.gems ? `<div class="line"><span>Gems</span><span>+${d.gems}</span></div>` : ``}
  ${d.socketBonus ? `<div class="line"><span>Socket Bonus</span><span>+${d.socketBonus}</span></div>` : ``}

  <div class="line total"><span>Total</span><span>${d.total}</span></div>
`;

      row.appendChild(box);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', buildStatBreakdowns);
  } else {
    buildStatBreakdowns();
  }
})();
</script>

<script>
(() => {
  const OFFSET = 14;
  const PAD = 12;

  function clamp(v, min, max){ return Math.max(min, Math.min(max, v)); }

  function positionTip(tip, x, y) {
    tip.classList.add('is-open');

    // Force reflow to ensure styles apply
    tip.offsetHeight;

    const r = tip.getBoundingClientRect();
    let tx = x + OFFSET;
    let ty = y + OFFSET;

    if (tx + r.width > window.innerWidth - PAD) tx = x - r.width - OFFSET;
    if (ty + r.height > window.innerHeight - PAD) ty = y - r.height - OFFSET;

    tx = clamp(tx, PAD, window.innerWidth - r.width - PAD);
    ty = clamp(ty, PAD, window.innerHeight - r.height - PAD);

    tip.style.left = tx + 'px';
    tip.style.top  = ty + 'px';
    tip.style.display = 'block'; // Explicitly set display
  }

  function closeTip(tip){
    tip.classList.remove('is-open');
    tip.style.display = 'none'; // Explicitly hide
  }

  // Move all tooltips to body on page load
  function initTooltips() {
    document.querySelectorAll('.stat-breakdown').forEach(tip => {
      tip.style.display = 'none'; // Start hidden
      document.body.appendChild(tip);
    });
  }

  let activeRow = null;
  let raf = 0;
  let lastX = 0, lastY = 0;

  document.addEventListener('mousemove', (e) => {
    const row = e.target.closest('.stat-row[data-stat]');
    
    if (!row) {
      if (activeRow) {
        const tip = activeRow.querySelector('.stat-breakdown') || 
                    document.querySelector(`.stat-breakdown[data-stat="${activeRow.dataset.stat}"]`);
        if (tip) closeTip(tip);
        activeRow = null;
      }
      return;
    }

    // Find tooltip by matching stat name
    let tip = Array.from(document.querySelectorAll('.stat-breakdown')).find(t => {
      const parent = t.dataset.stat;
      return parent === row.dataset.stat;
    });

    if (!tip) {
      // Fallback: tooltip might still be in the row
      tip = row.querySelector('.stat-breakdown');
    }

    if (!tip) return;

    // If we switched rows, close the old one
    if (activeRow && activeRow !== row) {
      const oldStat = activeRow.dataset.stat;
      const oldTip = Array.from(document.querySelectorAll('.stat-breakdown')).find(t => 
        t.dataset.stat === oldStat
      );
      if (oldTip) closeTip(oldTip);
    }

    activeRow = row;
    lastX = e.clientX;
    lastY = e.clientY;

    if (!raf) {
      raf = requestAnimationFrame(() => {
        raf = 0;
        if (!activeRow) return;
        if (tip) positionTip(tip, lastX, lastY);
      });
    }
  }, { passive: true });

  // Initialize when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initTooltips);
  } else {
    initTooltips();
  }
})();
</script>