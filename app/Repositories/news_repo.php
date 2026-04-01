<?php
/**
 * app/news_repo.php
 * News repository (legacy) - manages news articles with flexible schema detection
 * NOTE: LIMIT/OFFSET are inlined (no bound params) for MariaDB native prepare compatibility.
 */

declare(strict_types=1);

/** Return set of news columns for the current DB. */
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
    return $cache = []; // table missing / no privilege
  }
}
function _has(PDO $pdo, string $col): bool { $c = _news_cols($pdo); return isset($c[strtolower($col)]); }

function _subtitle_expr(PDO $pdo): string {
  return _has($pdo,'subtitle') ? "COALESCE(subtitle,'') AS subtitle"
       : (_has($pdo,'excerpt')  ? "COALESCE(excerpt,'')  AS subtitle"
       : "'' AS subtitle");
}
function _content_expr(PDO $pdo): string {
  return _has($pdo,'content') ? "COALESCE(content,'') AS content" : "'' AS content";
}
function _author_expr(PDO $pdo): string {
  return _has($pdo,'author') ? "COALESCE(author,'') AS author" : "'' AS author";
}

/** Published predicate */
function _published_pred(PDO $pdo): string {
  if (_has($pdo,'published'))      return "published = 1";
  if (_has($pdo,'is_published'))   return "is_published = 1";
  if (_has($pdo,'published_at'))   return "published_at IS NOT NULL";
  return "1=1";
}

/** ORDER BY */
function _order_expr(PDO $pdo): string {
  if (_has($pdo,'published_at')) return "published_at DESC";
  if (_has($pdo,'created_at'))   return "created_at DESC";
  if (_has($pdo,'updated_at'))   return "updated_at DESC";
  if (_has($pdo,'id'))           return "id DESC";
  return "1";
}

function _clamp_limit(int $n, int $max = 50): int {
  $n = max(1, $n);
  return min($n, $max);
}

/** Latest news */
function news_latest($pdo, int $limit = 3): array {
  if (!$pdo instanceof PDO) return _news_demo($limit);
  if (_news_cols($pdo) === [])    return _news_demo($limit);

  $limit = _clamp_limit($limit, 50);

  $subtitle = _subtitle_expr($pdo);
  $content  = _content_expr($pdo);
  $author   = _author_expr($pdo);
  $where    = _published_pred($pdo);
  $order    = _order_expr($pdo);

  $sql = "SELECT id, slug, title, $subtitle, $content, $author,
                 " . (_has($pdo,'published_at') ? "published_at" : "NULL AS published_at") . "
          FROM news
          WHERE $where
          ORDER BY $order
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

/** Paginated list */
function news_paginated($pdo, int $page = 1, int $per = 10): array {
  if (!$pdo instanceof PDO) return ['items' => _news_demo($per), 'total' => 3];
  if (_news_cols($pdo) === [])    return ['items' => _news_demo($per), 'total' => 3];

  $per    = _clamp_limit($per, 50);
  $page   = max(1, $page);
  $offset = ($page - 1) * $per;

  $subtitle = _subtitle_expr($pdo);
  $content  = _content_expr($pdo);
  $author   = _author_expr($pdo);
  $where    = _published_pred($pdo);
  $order    = _order_expr($pdo);

  try {
    $total = (int)$pdo->query("SELECT COUNT(*) FROM news WHERE $where")->fetchColumn();

    $sql = "SELECT id, slug, title, $subtitle, $content, $author,
                   " . (_has($pdo,'published_at') ? "published_at" : "NULL AS published_at") . "
            FROM news
            WHERE $where
            ORDER BY $order
            LIMIT {$per} OFFSET {$offset}";
    $st = $pdo->prepare($sql);
    $st->execute();
    $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return ['items' => $items, 'total' => $total];
  } catch (Throwable $e) {
    return ['items' => _news_demo($per), 'total' => 3];
  }
}

/** Find by slug */
function news_find_by_slug($pdo, string $slug): ?array {
  if (!$pdo instanceof PDO) {
    foreach (_news_demo(3) as $n) if (($n['slug'] ?? '') === $slug) return $n + ['content' => '<p>Demo content.</p>'];
    return null;
  }
  if (_news_cols($pdo) === []) return null;

  $subtitle = _subtitle_expr($pdo);
  $content  = _content_expr($pdo);
  $author   = _author_expr($pdo);
  $where    = _published_pred($pdo);

  $st = $pdo->prepare("
    SELECT id, slug, title, $subtitle, $content, $author,
           " . (_has($pdo,'published_at') ? "published_at" : "NULL AS published_at") . "
    FROM news
    WHERE slug = :slug AND $where
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

/** Demo fallback */
function _news_demo(int $n = 3): array {
  $demo = [
    ['slug'=>'welcome','title'=>'Welcome to the new site','subtitle'=>'Fresh design and faster pages.','content'=>'','author'=>'Thorium Team','published_at'=>date('Y-m-d H:i:s', time()-86400)],
    ['slug'=>'shop-soon','title'=>'Shop is coming soon','subtitle'=>'Launch rewards and cosmetics incoming.','content'=>'','author'=>'Staff','published_at'=>date('Y-m-d H:i:s', time()-172800)],
    ['slug'=>'realm-health','title'=>'Realm health & ladders','subtitle'=>'Status widgets and leaderboards next.','content'=>'','author'=>'Thorium Team','published_at'=>date('Y-m-d H:i:s', time()-259200)],
  ];
  return array_slice($demo, 0, $n);
}
