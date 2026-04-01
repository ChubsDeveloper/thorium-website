<?php
/**
 * app/downloads_repo.php - FIXED VERSION
 * Data repository - manages downloads data operations
 */
declare(strict_types=1);

function downloads_column_exists(PDO $pdo, string $col): bool {
  try {
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'downloads'
              AND COLUMN_NAME = :c";
    $st = $pdo->prepare($sql);
    $st->execute([':c' => $col]);
    return (bool)$st->fetchColumn();
  } catch (Exception $e) {
    return false;
  }
}

function downloads_list(PDO $pdo, ?string $platform = null, bool $onlyActive = true): array {
  try {
    // Check if table exists first
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'downloads'");
    if (!$tableCheck->fetch()) {
      downloads_create_table($pdo);
    }

    $hasUnit   = downloads_column_exists($pdo, 'size_unit');
    $hasLabel  = downloads_column_exists($pdo, 'size_label');
    $hasBytes  = downloads_column_exists($pdo, 'size_bytes');
    $hasStor   = downloads_column_exists($pdo, 'storage_path');
    $hasMime   = downloads_column_exists($pdo, 'mime_type');
    $hasCount  = downloads_column_exists($pdo, 'download_count');

    $cols = "id, name, href, platform, category, version, size_mb, sha256, notes, required, sort, is_active, created_at, updated_at";
    if ($hasUnit)  { $cols .= ", size_unit"; }
    if ($hasLabel) { $cols .= ", size_label"; }
    if ($hasBytes) { $cols .= ", size_bytes"; }
    if ($hasStor)  { $cols .= ", storage_path"; }
    if ($hasMime)  { $cols .= ", mime_type"; }
    if ($hasCount) { $cols .= ", download_count"; }

    $sql    = "SELECT $cols FROM downloads";
    $where  = [];
    $params = [];

    if ($onlyActive) { 
      $where[] = "is_active = 1"; 
    }
    
    if ($platform !== null && $platform !== '' && $platform !== '*') {
      $where[] = "(platform = :p OR platform IS NULL OR platform = 'all' OR platform = '' OR FIND_IN_SET(:p, platform) > 0)";
      $params[':p'] = $platform;
    }
    
    if ($where) { 
      $sql .= " WHERE " . implode(" AND ", $where); 
    }
    
    $sql .= " ORDER BY sort ASC, id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    
    return $results;
  } catch (Exception $e) {
    error_log("Downloads error: " . $e->getMessage());
    return [];
  }
}

function downloads_create_table(PDO $pdo): void {
  try {
    $sql = "
      CREATE TABLE IF NOT EXISTS downloads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(200) NOT NULL,
        href TEXT NOT NULL,
        storage_path VARCHAR(255) NULL,
        platform SET('win','mac','linux') NOT NULL DEFAULT 'win,mac,linux',
        category VARCHAR(60) NOT NULL DEFAULT '',
        version VARCHAR(60) NOT NULL DEFAULT '',
        size_mb DECIMAL(10,2) NULL,
        size_bytes BIGINT NULL,
        mime_type VARCHAR(120) NULL,
        download_count INT UNSIGNED NOT NULL DEFAULT 0,
        size_unit ENUM('MB','GB') NULL,
        size_label VARCHAR(32) NULL,
        sha256 CHAR(64) NULL,
        notes TEXT NULL,
        required TINYINT(1) NOT NULL DEFAULT 0,
        sort INT NOT NULL DEFAULT 999,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_active (is_active),
        INDEX idx_platform (platform),
        INDEX idx_sort (sort)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ";
    $pdo->exec($sql);
  } catch (Exception $e) {
    error_log("Failed to create downloads table: " . $e->getMessage());
  }
}

function downloads_insert_samples(PDO $pdo): void {
  try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM downloads");
    $count = (int)$stmt->fetchColumn();
    
    if ($count === 0) {
      $samples = [
        [
          'name' => 'WoW Client (3.3.5a)',
          'href' => 'https://example.com/client.zip',
          'platform' => 'win,mac,linux',
          'category' => 'Client',
          'version' => '3.3.5a',
          'size_mb' => 4096.00,
          'required' => 1,
          'sort' => 1,
          'notes' => 'Official World of Warcraft client'
        ],
        [
          'name' => 'Recommended Addons',
          'href' => 'https://example.com/addons.zip',
          'platform' => 'win,mac,linux',
          'category' => 'Addons',
          'version' => '3.3.5',
          'size_mb' => 25.50,
          'required' => 0,
          'sort' => 2,
          'notes' => 'Essential addons for enhanced gameplay'
        ]
      ];
      
      $sql = "INSERT INTO downloads (name, href, platform, category, version, size_mb, required, sort, notes, is_active) 
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)";
      $stmt = $pdo->prepare($sql);
      
      foreach ($samples as $sample) {
        $stmt->execute([
          $sample['name'],
          $sample['href'],
          $sample['platform'],
          $sample['category'],
          $sample['version'],
          $sample['size_mb'],
          $sample['required'],
          $sample['sort'],
          $sample['notes']
        ]);
      }
    }
  } catch (Exception $e) {
    error_log("Failed to insert sample downloads: " . $e->getMessage());
  }
}
