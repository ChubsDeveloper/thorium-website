<?php
/**
 * app/Repositories/downloads_repository.php
 * Data access repository - manages database operations for downloads
 */
declare(strict_types=1);

// app/Repositories/DownloadsRepository.php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Repository;

class DownloadsRepository extends Repository
{
    protected string $table = 'downloads';

    /** Initialize the class instance. */
    public function __construct($app)
    {
        parent::__construct($app);
        $this->bootstrap();
    }

    /** Process and return array data. */
    public function getAll(?string $platform = null, bool $activeOnly = false): array
    {
        $conditions = [];
        $params = [];

        if ($activeOnly) {
            $conditions[] = 'is_active = 1';
        }

        if ($platform) {
            $conditions[] = '(platform IS NULL OR platform = "" OR FIND_IN_SET(?, platform) > 0)';
            $params[] = $platform;
        }

        $whereClause = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $sql = "SELECT * FROM {$this->table} {$whereClause} ORDER BY sort ASC, name ASC";

        return $this->fetchAll($sql, $params);
    }

    /** Handle getById operation. */
    public function getById(int $id): ?array
    {
        $sql = "SELECT * FROM {$this->table} WHERE id = ? LIMIT 1";
        return $this->fetchOne($sql, [$id]);
    }

    /** Calculate and return numeric value. */
    public function create(array $data): int
    {
        $this->validateRequired($data, ['name']);
        
        $sql = "INSERT INTO {$this->table} 
                (name, category, version, platform, href, storage_path, size_bytes, size_mb, 
                 mime_type, sha256, notes, required, sort, is_active) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $params = [
            $data['name'],
            $data['category'] ?? '',
            $data['version'] ?? '',
            $data['platform'] ?? '',
            $data['href'] ?? '',
            $data['storage_path'] ?? null,
            $data['size_bytes'] ?? null,
            $data['size_mb'] ?? null,
            $data['mime_type'] ?? '',
            $data['sha256'] ?? '',
            $data['notes'] ?? '',
            !empty($data['required']) ? 1 : 0,
            (int)($data['sort'] ?? 999),
            !empty($data['is_active']) ? 1 : 0
        ];
        
        $this->execute($sql, $params);
        return (int)$this->pdo->lastInsertId();
    }

    /** Check condition and return boolean result. */
    public function update(int $id, array $data): bool
    {
        $allowedFields = [
            'name', 'category', 'version', 'platform', 'href', 'storage_path',
            'size_bytes', 'size_mb', 'mime_type', 'sha256', 'notes', 'required', 'sort', 'is_active'
        ];
        
        $setParts = [];
        $params = [];
        
        foreach ($data as $field => $value) {
            if (in_array($field, $allowedFields)) {
                $setParts[] = "{$field} = ?";
                $params[] = $value;
            }
        }
        
        if (empty($setParts)) {
            return false;
        }
        
        $params[] = $id;
        $sql = "UPDATE {$this->table} SET " . implode(', ', $setParts) . ", updated_at = CURRENT_TIMESTAMP WHERE id = ?";
        
        $stmt = $this->execute($sql, $params);
        return $stmt->rowCount() > 0;
    }

    /** Check condition and return boolean result. */
    public function delete(int $id): bool
    {
        $sql = "DELETE FROM {$this->table} WHERE id = ?";
        $stmt = $this->execute($sql, [$id]);
        return $stmt->rowCount() > 0;
    }

    /** Process and return array data. */
    public function generateManifest(?string $platform = null, ?string $sinceIso = null): array
    {
        $downloads = $this->getAll($platform, true);
        
        // Filter by date if provided
        if ($sinceIso) {
            $timestamp = strtotime($sinceIso);
            if ($timestamp) {
                $downloads = array_filter($downloads, function($item) use ($timestamp) {
                    $updated = strtotime($item['updated_at'] ?? $item['created_at'] ?? '1970-01-01');
                    return $updated >= $timestamp;
                });
            }
        }

        $items = [];
        foreach ($downloads as $download) {
            $platforms = [];
            if (!empty($download['platform'])) {
                $platforms = array_values(array_filter(array_map('trim', explode(',', $download['platform']))));
            }

            $items[] = [
                'id' => (int)$download['id'],
                'name' => $download['name'],
                'category' => $download['category'] ?? '',
                'version' => $download['version'] ?? '',
                'required' => !empty($download['required']),
                'platforms' => $platforms,
                'url' => $this->generateDownloadUrl($download),
                'sizeBytes' => $this->normalizeSizeBytes($download),
                'sha256' => !empty($download['sha256']) ? strtolower($download['sha256']) : '',
                'notes' => $download['notes'] ?? '',
                'updatedAt' => $download['updated_at'] ?? $download['created_at'] ?? '',
                'mime' => $download['mime_type'] ?? ''
            ];
        }

        return [
            'generatedAt' => gmdate('c'),
            'count' => count($items),
            'items' => $items
        ];
    }

    /** Process and return string data. */
    private function generateDownloadUrl(array $download): string
    {
        // If it's an external link, return as-is
        if (!empty($download['href'])) {
            return $download['href'];
        }

        // For local files, generate signed URL
        if (!empty($download['storage_path'])) {
            $config = $this->app->getConfig('downloads', []);
            $ttl = (int)($config['link_ttl'] ?? 3600);
            $secret = $config['hmac_secret'] ?? '';
            
            $id = (int)$download['id'];
            $expires = time() + max(60, $ttl);
            $data = "{$id}|{$expires}";
            
            if (!empty($download['sha256'])) {
                $data .= '|' . strtolower($download['sha256']);
            }
            
            $signature = hash_hmac('sha256', $data, $secret);
            $query = http_build_query(['id' => $id, 'e' => $expires, 'sig' => $signature]);
            
            $base = $this->app->getConfig('base_url', '');
            return rtrim($base, '/') . '/download?' . $query;
        }

        return '#';
    }

    /** Handle normalizeSizeBytes operation. */
    private function normalizeSizeBytes(array $download): ?int
    {
        if (isset($download['size_bytes']) && $download['size_bytes'] !== null) {
            return (int)$download['size_bytes'];
        }
        
        if (isset($download['size_mb']) && $download['size_mb'] !== null && $download['size_mb'] !== '') {
            return (int)round((float)$download['size_mb'] * 1024 * 1024);
        }
        
        return null;
    }

    /** Perform operation without return value. */
    private function bootstrap(): void
    {
        $schema = "
            CREATE TABLE IF NOT EXISTS {$this->table} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                category VARCHAR(100) DEFAULT '',
                version VARCHAR(50) DEFAULT '',
                platform VARCHAR(100) DEFAULT '',
                href VARCHAR(500) DEFAULT '',
                storage_path VARCHAR(500) NULL,
                size_bytes BIGINT NULL,
                size_mb DECIMAL(10,2) NULL,
                mime_type VARCHAR(100) DEFAULT '',
                sha256 VARCHAR(64) DEFAULT '',
                notes TEXT DEFAULT '',
                required TINYINT(1) DEFAULT 0,
                sort INT DEFAULT 999,
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_active (is_active),
                INDEX idx_platform (platform),
                INDEX idx_sort (sort)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";
        $this->createTableIfNotExists($schema);
    }
}
