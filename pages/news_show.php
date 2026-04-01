<?php
/**
 * pages/news_show.php
 * Page template - renders the news_show page (single article)
 */

if (!function_exists('e')) {
  function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('format_date')) {
  function format_date($s){ return date('M j, Y', strtotime($s)); }
}

// Ensure parse_wow_colors is available
if (!function_exists('parse_wow_colors')) {
    $helperFile = dirname(__DIR__) . '/app/helpers.php';
    if (is_file($helperFile)) {
        require_once $helperFile;
    }
}

/**
 * Robust body renderer:
 * - If empty: fallback to subtitle/excerpt.
 * - If plain text: escape + nl2br for line breaks.
 * - If HTML: parse WoW colors (assumed trusted from admin).
 */
function render_news_body(?string $content, string $subtitle = '', string $excerpt = ''): string {
  $content  = trim((string)$content);
  $subtitle = trim($subtitle);
  $excerpt  = trim($excerpt);

  if ($content === '') {
    $fallback = $subtitle !== '' ? $subtitle : $excerpt;
    return nl2br(e($fallback));
  }

  // Apply WoW color parsing
  if (!function_exists('parse_wow_colors')) {
    // Inline parser if helpers not loaded
    if (strpos($content, '|cff') !== false) {
      $content = str_replace(['&#124;', '&pipe;', '&#x7C;'], '|', $content);
      $content = preg_replace_callback('~\|cff([0-9a-fA-F]{6})(.*?)\|r~s', function($m) {
        return '<span style="color: #' . strtolower($m[1]) . ';">' . $m[2] . '</span>';
      }, $content);
    }
  } else {
    $content = parse_wow_colors($content);
  }

  if (preg_match('/<\/?[a-z][\s\S]*>/i', $content)) {
    return $content; // trusted admin HTML (with parsed colors)
  }
  return nl2br($content); // plain text with colors (no escaping needed, colors are pre-parsed)
}

$readTime = function(string $html = '', int $wpm = 220): string {
  $text = trim(strip_tags($html));
  $words = max(1, str_word_count($text));
  $mins  = max(1, (int)ceil($words / $wpm));
  return $mins . ' min read';
};
?>

<?php if (!isset($article)): ?>
  <section class="py-16 text-center">
    <h1 class="text-3xl font-bold">Article not found</h1>
    <a class="mt-4 inline-block btn-ghost" href="/news">&larr; Back to News</a>
  </section>
<?php else: ?>
  <?php
    // Accept either 'subtitle' or 'sub_title'
    $subtitle = (string)($article['subtitle'] ?? $article['sub_title'] ?? '');
    $excerpt  = (string)($article['excerpt']  ?? '');
    $content  = (string)($article['content']  ?? '');

    $bodyHtml = render_news_body($content, $subtitle, $excerpt);
    $readSrc  = $content !== '' ? $content : ($excerpt !== '' ? $excerpt : $subtitle);
  ?>
  <article class="max-w-3xl">
    <?php if (!empty($article['cover_url'])): ?>
      <figure class="news-media mb-5">
        <img class="news-img" src="<?= e($article['cover_url']) ?>" alt="">
        <span class="news-media-overlay"></span>
        <figcaption class="news-media-cap">
          <a href="/news" class="news-back">&larr; Back</a>
        </figcaption>
      </figure>
    <?php endif; ?>

    <a href="/news" class="text-sm text-neutral-300 hover:text-indigo-300">&larr; Back to News</a>
    <!-- DEBUG: news_show.php title parsing -->
    <h1 class="mt-2 text-3xl font-extrabold"><?php
      $title = (string)($article['title'] ?? '');
      echo '<!-- DEBUG: raw title = ' . htmlspecialchars($title) . ' -->';
      echo '<!-- DEBUG: strpos |cff = ' . (strpos($title, '|cff') !== false ? 'FOUND' : 'NOT FOUND') . ' -->';
      if (strpos($title, '|cff') !== false) {
        echo '<!-- DEBUG: Processing color codes in title -->';
        $title = str_replace(['&#124;', '&pipe;', '&#x7C;'], '|', $title);
        $title = preg_replace_callback('~\|cff([0-9a-fA-F]{6})(.*?)\|r~s', function($m) {
          return '<span style="color: #' . strtolower($m[1]) . ';">' . htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8') . '</span>';
        }, $title);
        echo $title;
      } else {
        echo e($title);
      }
    ?></h1>

    <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-neutral-300">
      <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] border border-amber-900/40 bg-amber-900/10 text-amber-200">News</span>
      <span><?= e(format_date($article['published_at'])) ?></span>
      <span class="opacity-40">•</span>
      <span><?= e($readTime($readSrc)) ?></span>
      <?php if (!empty($article['author'])): ?>
        <span class="opacity-40">•</span>
        <span class="author-chip is-sheen-periodic"><?= e($article['author']) ?></span>
      <?php endif; ?>
    </div>

    <?php if ($subtitle !== ''): ?>
      <p class="mt-3 text-neutral-300"><?php
        if (strpos($subtitle, '|cff') !== false) {
          $subtitle = str_replace(['&#124;', '&pipe;', '&#x7C;'], '|', $subtitle);
          $subtitle = preg_replace_callback('~\|cff([0-9a-fA-F]{6})(.*?)\|r~s', function($m) {
            return '<span style="color: #' . strtolower($m[1]) . ';">' . htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8') . '</span>';
          }, $subtitle);
          echo $subtitle;
        } else {
          echo e($subtitle);
        }
      ?></p>
    <?php elseif ($excerpt !== ''): ?>
      <p class="mt-3 text-neutral-300"><?php
        if (strpos($excerpt, '|cff') !== false) {
          $excerpt = str_replace(['&#124;', '&pipe;', '&#x7C;'], '|', $excerpt);
          $excerpt = preg_replace_callback('~\|cff([0-9a-fA-F]{6})(.*?)\|r~s', function($m) {
            return '<span style="color: #' . strtolower($m[1]) . ';">' . htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8') . '</span>';
          }, $excerpt);
          echo $excerpt;
        } else {
          echo e($excerpt);
        }
      ?></p>
    <?php endif; ?>

    <div class="prose prose-invert mt-6 max-w-none news-article-body">
      <?= $bodyHtml ?>
    </div>
  </article>
<?php endif; ?>
