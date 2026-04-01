<?php
/**
 * pages/news.php
 * News listing with overflow-aware "Read more" (installed Tailwind friendly)
 */

if (!function_exists('e')) {
  function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('format_date')) {
  function format_date($s){ return date('M j, Y', strtotime($s)); }
}
if (!function_exists('excerpt')) {
  function excerpt($s, $n=160){
    $s = trim((string)$s);
    return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1) . '…' : $s;
  }
}

// Ensure parse_wow_colors is available
if (!function_exists('parse_wow_colors')) {
    $helperFile = dirname(__DIR__) . '/app/helpers.php';
    if (is_file($helperFile)) {
        require_once $helperFile;
    }
}

$pageNum = max(1, (int)($_GET['page'] ?? 1));
$perPage = 9;

$pg    = news_paginated($pdo, $pageNum, $perPage);
$items = $pg['items'];
$total = (int)$pg['total'];
$pages = max(1, (int)ceil($total / $perPage));

$readTime = function(array $row, int $wpm=220): string {
  $text  = trim(strip_tags(($row['content'] ?? '') ?: ($row['excerpt'] ?? '') ?: ($row['title'] ?? '')));
  $words = max(1, str_word_count($text));
  $mins  = max(1, (int)ceil($words / $wpm));
  return $mins . ' min';
};
?>
<section class="container px-4 pt-10" id="news">

  <!-- Local, scoped styles for the collapsible preview -->
  <style>
    /* Only affect this page's cards */
    .news-card .news-body {
      overflow: hidden;
      transition: max-height 220ms ease;
    }
    /* Collapsed height ≈ 3 lines for text-sm (20px lh) -> 60-64px */
    .news-card .news-body[data-collapsed="true"] {
      max-height: 64px; /* threshold measured by JS to decide if button is needed */
    }
    .news-card .toggle-row[hidden] { display: none !important; }
  </style>

  <div class="flex items-end justify-between gap-3">
    <div>
      <h1 class="h-display text-3xl font-extrabold">News</h1>
      <p class="mt-1 muted">Announcements and updates.</p>
    </div>
    <a href="/rss.xml" class="hidden md:inline-flex btn-ghost text-sm" aria-label="RSS feed">RSS</a>
  </div>

  <div class="mt-6 grid gap-5 md:grid-cols-3">
    <?php foreach ($items as $idx => $n): ?>
      <?php
        // Accept either 'subtitle' or 'sub_title' from repo/DB
        $subtitle     = trim((string)($n['subtitle'] ?? $n['sub_title'] ?? ''));
        $rawExcerpt   = trim((string)($n['excerpt']  ?? ''));
        $showSubtitle = ($subtitle !== '');
        $showExcerpt  = ($rawExcerpt !== '');
        $excerptOut   = $showExcerpt ? excerpt($rawExcerpt, 160) : '';

        // Inline preview text (outside the link, collapsible)
        // Prefer a dedicated preview if present; else derive a slightly longer snippet from content; else fall back to excerpt.
        $rawPreview = trim((string)($n['content_preview'] ?? ''));
        if ($rawPreview === '') {
          $contentText = trim(strip_tags((string)($n['content'] ?? '')));
          $rawPreview  = $contentText !== '' ? excerpt($contentText, 420) : $rawExcerpt;
        }

        $slug   = (string)($n['slug'] ?? ('n-'.$idx));
        $bodyId = 'news-body-'.preg_replace('~[^a-z0-9\-]+~i', '-', $slug);
        $href   = $slug !== '' ? '/news/'.rawurlencode($slug) : '#';
      ?>
      <article class="rough-card rough-card-hover group overflow-hidden news-card">
        <!-- Clickable card -->
        <a href="<?= e($href) ?>" class="block focus:outline-none" aria-label="<?= e($n['title'] ?? 'Read') ?>">
          <?php if (!empty($n['cover_url'])): ?>
            <figure class="news-media">
              <img class="news-img" src="<?= e($n['cover_url']) ?>" alt="">
              <span class="news-media-overlay"></span>
              <figcaption class="news-chip-wrap">
                <span class="news-chip">News</span>
              </figcaption>
            </figure>
          <?php endif; ?>

          <div class="p-5">
            <div class="flex items-center gap-2 text-xs text-neutral-400">
              <?php if (empty($n['cover_url'])): ?>
                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] border border-amber-900/40 bg-amber-900/10 text-amber-200">News</span>
              <?php endif; ?>
              <?php if (!empty($n['published_at'])): ?>
                <span><?= e(format_date($n['published_at'])) ?></span>
                <span class="opacity-40">•</span>
              <?php endif; ?>
              <span><?= e($readTime($n)) ?></span>
              <?php if (!empty($n['author'])): ?>
                <span class="opacity-40">•</span>
                <span class="author-chip is-sheen-periodic"><?= e($n['author']) ?></span>
              <?php endif; ?>
            </div>

            <h3 class="mt-2 font-semibold text-[18px] group-hover:text-ember-500 transition clamp-2">
              <?= e($n['title'] ?? '') ?>
            </h3>

            <?php if ($showSubtitle): ?>
              <p class="mt-1 text-sm text-neutral-400 clamp-2"><?= e($subtitle) ?></p>
            <?php endif; ?>

            <?php if ($showExcerpt): ?>
              <p class="mt-2 text-sm text-neutral-300 clamp-3"><?= e($excerptOut) ?></p>
            <?php endif; ?>
          </div>
          <span class="card-radial"></span>
        </a>

        <!-- Collapsible preview OUTSIDE the link -->
        <?php if ($rawPreview !== ''): ?>
          <div class="px-5 pb-5">
            <div id="<?= e($bodyId) ?>"
                 class="news-body mt-1 text-sm text-neutral-300"
                 data-collapsed="true">
              <?php
                // Parse WoW colors first on raw text, THEN apply safe display
                $previewText = $rawPreview;

                // Inline WoW color parser (test version)
                if (!function_exists('parse_wow_colors')) {
                  function parse_wow_colors_inline($text) {
                    if ($text === '') return $text;
                    $text = str_replace(['&#124;', '&pipe;', '&#x7C;'], '|', $text);
                    if (strpos($text, '|cff') === false) return $text;
                    return preg_replace_callback('~\|cff([0-9a-fA-F]{6})(.*?)\|r~s', function($m) {
                      return '<span style="color: #' . strtolower($m[1]) . ';">' . $m[2] . '</span>';
                    }, $text);
                  }
                  $previewText = parse_wow_colors_inline($previewText);
                } else {
                  $previewText = parse_wow_colors($previewText);
                }

                echo nl2br($previewText);
              ?>
            </div>

            <div class="toggle-row">
              <button
                type="button"
                class="mt-3 action-chip action-chip--vote blockwide"
                data-news-toggle="#<?= e($bodyId) ?>"
                aria-controls="<?= e($bodyId) ?>"
                aria-expanded="false">
                Read more
              </button>
            </div>
          </div>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </div>

  <?php if ($pages > 1): ?>
    <nav class="mt-6 flex items-center gap-2">
      <?php if ($pageNum > 1): ?>
        <a href="/news?page=<?= $pageNum-1 ?>" class="px-3 py-1.5 rounded-md border border-amber-900/40 text-sm hover:bg-amber-900/10">Prev</a>
      <?php endif; ?>
      <?php for ($i=1; $i<=$pages; $i++): ?>
        <a href="/news?page=<?= $i ?>" class="px-3 py-1.5 rounded-md border border-amber-900/40 text-sm <?= $i===$pageNum?'bg-amber-900/20 text-amber-200':'hover:bg-amber-900/10' ?>">
          <?= $i ?>
        </a>
      <?php endfor; ?>
      <?php if ($pageNum < $pages): ?>
        <a href="/news?page=<?= $pageNum+1 ?>" class="px-3 py-1.5 rounded-md border border-amber-900/40 text-sm hover:bg-amber-900/10">Next</a>
      <?php endif; ?>
    </nav>
  <?php endif; ?>
</section>

<!-- Inline enhancer (self + inline allowed by your CSP) -->
<script>
(function(){
  function px(v){ return typeof v === 'string' && v.endsWith('px') ? parseFloat(v) : v; }

  function setup(btn){
    var sel  = btn.getAttribute('data-news-toggle');
    var body = sel && document.querySelector(sel);
    var row  = btn.closest('.toggle-row');
    if (!body || !row) { if (row) row.hidden = true; return; }

    // Measure collapsed threshold from CSS (max-height when collapsed)
    body.setAttribute('data-collapsed', 'true');
    var collapsedMax = px(getComputedStyle(body).maxHeight) || 0;

    function needsToggle(){
      // Show button only if content is taller than collapsed height
      return body.scrollHeight > collapsedMax + 2;
    }
    function set(expanded){
      btn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      body.setAttribute('data-collapsed', expanded ? 'false' : 'true');
      // Animate expand/collapse by setting an explicit max-height when expanding
      if (expanded) {
        body.style.maxHeight = body.scrollHeight + 'px';
        btn.firstChild && btn.firstChild.nodeType === 3 ? (btn.firstChild.nodeValue = 'Show less') : (btn.textContent = 'Show less');
      } else {
        body.style.maxHeight = ''; // back to CSS value (64px)
        btn.firstChild && btn.firstChild.nodeType === 3 ? (btn.firstChild.nodeValue = 'Read more') : (btn.textContent = 'Read more');
      }
    }

    // Initial visibility
    if (!needsToggle()) { row.hidden = true; }
    set(false);

    // Toggle without following the card link
    btn.addEventListener('click', function(ev){
      ev.preventDefault();
      ev.stopPropagation();
      set(btn.getAttribute('aria-expanded') !== 'true');
    });

    // Re-evaluate on resize (text wrapping can change height)
    var t;
    window.addEventListener('resize', function(){
      clearTimeout(t);
      t = setTimeout(function(){
        var expanded = btn.getAttribute('aria-expanded') === 'true';
        if (!expanded) {
          row.hidden = !needsToggle();
        } else {
          // keep expanded height in sync
          body.style.maxHeight = body.scrollHeight + 'px';
        }
      }, 120);
    }, { passive: true });
  }

  // Run now or on DOM ready — whichever is appropriate
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function(){
      document.querySelectorAll('[data-news-toggle]').forEach(setup);
    });
  } else {
    document.querySelectorAll('[data-news-toggle]').forEach(setup);
  }
})();
</script>
