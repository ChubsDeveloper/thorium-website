<?php
/**
 * News Repository
 *
 * Flexible news system supporting multiple database schemas with automatic
 * column detection and fallback handling. Treats empty strings as NULL and
 * provides demo content when database is unavailable. Optimized for MariaDB
 * with inlined LIMIT clauses for native prepare compatibility.
 */

declare(strict_types=1);

// =============================================================================
// Database Schema Detection
// =============================================================================

function _news_cols(PDO $pdo): array {
  static $cache = null;
  if ($cache !== null) return $cache;

  $q = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'news'";
  $st = $pdo->prepare($q);
  try {
    $st->execute();
    $cols = array_map('strtolower', array_column($st->fetchAll(PDO::FETCH_ASSOC), 'COLUMN_NAME'));
    return $cache = array_fill_keys($cols, true);
  } catch (Throwable $e) {
    return $cache = [];
  }
}

function _has(PDO $pdo, string $col): bool {
  $c = _news_cols($pdo);
  return isset($c[strtolower($col)]);
}

/** Build COALESCE over present columns; empty strings treated as NULL. */
function _coalesce_expr(PDO $pdo, array $candidates, string $alias): string {
  $parts = [];
  foreach ($candidates as $c) {
    if (_has($pdo, $c)) $parts[] = "NULLIF(TRIM($c), '')";
  }
  return $parts ? ("COALESCE(" . implode(', ', $parts) . ", '') AS $alias") : "'' AS $alias";
}

/* ---------- Column expressions ---------- */
function _subtitle_expr(PDO $pdo): string {
  // Prefer explicit subtitle, then sub_title, finally excerpt
  return _coalesce_expr($pdo, ['subtitle', 'sub_title', 'excerpt'], 'subtitle');
}
function _excerpt_expr(PDO $pdo): string {
  return _coalesce_expr($pdo, ['excerpt'], 'excerpt');
}
function _content_expr(PDO $pdo): string {
  // IMPORTANT: ensure renderer always gets something in 'content'
  // Order: content > content_preview > body > excerpt
  return _coalesce_expr($pdo, ['content', 'content_preview', 'body', 'excerpt'], 'content');
}
/** Still expose content_preview independently (if other UIs need it) */
function _content_preview_expr(PDO $pdo): string {
  return _coalesce_expr($pdo, ['content_preview', 'content', 'body', 'excerpt'], 'content_preview');
}
function _author_expr(PDO $pdo): string {
  return _coalesce_expr($pdo, ['author'], 'author');
}
function _cover_expr(PDO $pdo): string {
  return _coalesce_expr($pdo, ['cover_url','cover','image_url','image','banner_url'], 'cover_url');
}
function _pinned_expr(PDO $pdo): string {
  return _has($pdo, 'pinned') ? "COALESCE(pinned, 0) AS pinned" : "0 AS pinned";
}

/* ---------- Published predicate & ordering ---------- */
function _published_pred(PDO $pdo): string {
  if (_has($pdo,'published'))      return "published = 1";
  if (_has($pdo,'is_published'))   return "is_published = 1";
  if (_has($pdo,'published_at'))   return "published_at IS NOT NULL";
  return "1=1";
}
function _order_expr(PDO $pdo): string {
  $pinned_order = _has($pdo, 'pinned') ? "pinned DESC, " : "";
  if (_has($pdo,'published_at')) return $pinned_order . "published_at DESC";
  if (_has($pdo,'created_at'))   return $pinned_order . "created_at DESC";
  if (_has($pdo,'updated_at'))   return $pinned_order . "updated_at DESC";
  if (_has($pdo,'id'))           return $pinned_order . "id DESC";
  return $pinned_order . "1";
}
function _clamp_limit(int $n, int $max = 50): int {
  $n = max(1, $n);
  return min($n, $max);
}

// =============================================================================
// News Query Functions
// =============================================================================

function news_latest($pdo, int $limit = 3): array {
  if (!$pdo instanceof PDO) return _news_demo($limit);
  if (_news_cols($pdo) === [])    return _news_demo($limit);

  $limit      = _clamp_limit($limit, 50);
  $subtitle   = _subtitle_expr($pdo);
  $excerpt    = _excerpt_expr($pdo);
  $content    = _content_expr($pdo);            // coalesces content_preview, body, excerpt
  $contentPrev= _content_preview_expr($pdo);
  $author     = _author_expr($pdo);
  $cover      = _cover_expr($pdo);
  $pinned     = _pinned_expr($pdo);
  $where      = _published_pred($pdo);
  $order      = _order_expr($pdo);

  $pubAt   = _has($pdo,'published_at') ? "published_at" : "NULL AS published_at";
  $slugCol = _has($pdo,'slug') ? "slug" : "'' AS slug";

  $sql = "SELECT id, {$slugCol}, title, {$subtitle}, {$excerpt}, {$contentPrev}, {$content}, {$author}, {$cover}, {$pinned}, {$pubAt}
          FROM news
          WHERE {$where}
          ORDER BY {$order}
          LIMIT {$limit}";
  try {
    $st = $pdo->prepare($sql);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return $rows ?: _news_demo($limit);
  } catch (Throwable $e) {
    return _news_demo($limit);
  }
}

function news_paginated($pdo, int $page = 1, int $per = 10): array {
  if (!$pdo instanceof PDO) return ['items' => _news_demo($per), 'total' => 3];
  if (_news_cols($pdo) === [])    return ['items' => _news_demo($per), 'total' => 3];

  $per     = _clamp_limit($per, 50);
  $page    = max(1, $page);
  $offset  = ($page - 1) * $per;

  $subtitle   = _subtitle_expr($pdo);
  $excerpt    = _excerpt_expr($pdo);
  $content    = _content_expr($pdo);            // coalesces content_preview, body, excerpt
  $contentPrev= _content_preview_expr($pdo);
  $author     = _author_expr($pdo);
  $cover      = _cover_expr($pdo);
  $pinned     = _pinned_expr($pdo);
  $where      = _published_pred($pdo);
  $order      = _order_expr($pdo);

  $pubAt   = _has($pdo,'published_at') ? "published_at" : "NULL AS published_at";
  $slugCol = _has($pdo,'slug') ? "slug" : "'' AS slug";

  try {
    $total = (int)$pdo->query("SELECT COUNT(*) FROM news WHERE {$where}")->fetchColumn();
    $sql = "SELECT id, {$slugCol}, title, {$subtitle}, {$excerpt}, {$contentPrev}, {$content}, {$author}, {$cover}, {$pinned}, {$pubAt}
            FROM news
            WHERE {$where}
            ORDER BY {$order}
            LIMIT {$per} OFFSET {$offset}";
    $st = $pdo->prepare($sql);
    $st->execute();
    $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return ['items' => $items, 'total' => $total];
  } catch (Throwable $e) {
    return ['items' => _news_demo($per), 'total' => 3];
  }
}

function news_find_by_slug($pdo, string $slug): ?array {
  if (!_has($pdo,'slug')) return null;
  if (_news_cols($pdo) === []) return null;

  $subtitle   = _subtitle_expr($pdo);
  $excerpt    = _excerpt_expr($pdo);
  $content    = _content_expr($pdo);            // coalesces content_preview, body, excerpt
  $contentPrev= _content_preview_expr($pdo);
  $author     = _author_expr($pdo);
  $cover      = _cover_expr($pdo);
  $pinned     = _pinned_expr($pdo);
  $where      = _published_pred($pdo);
  $pubAt      = _has($pdo,'published_at') ? "published_at" : "NULL AS published_at";

  $st = $pdo->prepare("
    SELECT id, slug, title, {$subtitle}, {$excerpt}, {$contentPrev}, {$content}, {$author}, {$cover}, {$pinned}, {$pubAt}
    FROM news
    WHERE slug = :slug AND {$where}
    LIMIT 1
  ");
  try {
    $st->execute([':slug' => $slug]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  } catch (Throwable $e) {
    return null;
  }
}

/* ---------- Demo fallback ---------- */
function _news_demo(int $n = 3): array {
  $demo = [
    [
      'slug'=>'welcome',
      'title'=>'Welcome to the new site',
      'subtitle'=>'Fresh design and faster pages.',
      'excerpt'=>'Fresh design and faster pages.',
      // Put an image link into content so the UI will embed it immediately
      'content_preview'=>'Check our Discord: https://discord.gg/yourinvite',
      'content'=>"Here's a test image:\nhttps://iili.io/Krp9QHB.jpg",
      'author'=>'Thorium Team',
      'cover_url'=>'',
      'pinned'=>1,
      'published_at'=>date('Y-m-d H:i:s', time()-86400),
    ],
    [
      'slug'=>'shop-soon',
      'title'=>'Shop is coming soon',
      'subtitle'=>'Launch rewards and cosmetics incoming.',
      'excerpt'=>'Launch rewards and cosmetics incoming.',
      'content_preview'=>'',
      'content'=>'',
      'author'=>'Staff',
      'cover_url'=>'',
      'pinned'=>0,
      'published_at'=>date('Y-m-d H:i:s', time()-172800),
    ],
    [
      'slug'=>'realm-health',
      'title'=>'Realm health & ladders',
      'subtitle'=>'Status widgets and leaderboards next.',
      'excerpt'=>'Status widgets and leaderboards next.',
      'content_preview'=>'',
      'content'=>'',
      'author'=>'Thorium Team',
      'cover_url'=>'',
      'pinned'=>0,
      'published_at'=>date('Y-m-d H:i:s', time()-259200),
    ],
  ];
  return array_slice($demo, 0, $n);
}
