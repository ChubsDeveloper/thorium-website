<?php
/**
 * Features Repository
 * 
 * Lightweight repository for managing website feature listings and displays.
 * Handles active feature retrieval with sorting and limit controls.
 * Optimized for MariaDB with inlined LIMIT clauses.
 */

declare(strict_types=1);

/**
 * Get database connection for features repository
 * 
 * @return PDO Database connection
 * @throws RuntimeException When no PDO connection is available
 */
function features_pdo(): PDO {
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        return $GLOBALS['pdo'];
    }
    if (function_exists('get_database_connection')) {
        $pdo = get_database_connection();
        if ($pdo instanceof PDO) return $pdo;
    }
    throw new RuntimeException('Features repo: PDO not available');
}

/**
 * Clamp limit value to reasonable bounds
 * 
 * @param int|null $limit Requested limit (null for no limit)
 * @param int $max Maximum allowed limit
 * @return int|null Clamped limit value
 */
function features_clamp_limit(?int $limit, int $max = 50): ?int {
    if ($limit === null) return null;
    $n = max(1, (int)$limit);
    return min($n, $max);
}

/**
 * Fetch active features ordered by sort_order, id.
 * @param int|null $limit  Limit rows (null = all)
 * @return array<int, array<string, mixed>>
 */
function features_all(?int $limit = 8): array {
    $pdo = features_pdo();
    $limit = features_clamp_limit($limit, 50);

    $sql = "SELECT id, title, blurb, icon, accent, url
            FROM features
            WHERE is_active=1
            ORDER BY sort_order ASC, id ASC";
    if ($limit !== null) {
        // inline limit to avoid placeholders in LIMIT for MariaDB native prepares
        $sql .= " LIMIT {$limit}";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
