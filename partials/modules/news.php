<?php
/**
 * partials/modules/news.php - News listing with image embedding + author display
 * Supports: bare URLs, <img>, <a href>, [img]...[/img], Markdown
 */

if (!function_exists('e')) {
  function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

// Ensure parse_wow_colors is available
if (!function_exists('parse_wow_colors')) {
    $helperFile = dirname(__DIR__, 2) . '/app/helpers.php';
    if (is_file($helperFile)) {
        require_once $helperFile;
    }
}
if (!function_exists('format_date')) {
  function format_date($s){ return $s ? date('M j, Y', strtotime($s)) : ''; }
}
if (!function_exists('excerpt')) {
  function excerpt($s, $n=160){
    $s = trim((string)$s);
    return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1) . '…' : $s;
  }
}

/* ===== CORE HELPERS ===== */


function is_allowed_image_host(string $host): bool {
  // Only owners can upload news, so allow any host
  return true;
}

function looks_like_image_path(string $path): bool {
  return (bool)preg_match('~\.(?:png|jpe?g|gif|webp|avif)(?:\?.*)?$~i', $path);
}

function build_figure_for_url(string $url): string {
  $parts = parse_url($url);
  if (!$parts || empty($parts['host'])) {
    return '';
  }
  
  $host = strtolower($parts['host']);
  $path = $parts['path'] ?? '';

  // Check if host is allowed
  if (!is_allowed_image_host($host)) {
    return '';
  }

  // Check if path looks like an image
  if (!looks_like_image_path($path)) {
    return '';
  }

  return '<figure class="news-embed"><img src="'.e($url).'" alt="Embedded image" loading="lazy"></figure>';
}

/* ===== HTML SANITIZATION ===== */

function sanitize_html(string $html): string {
  if ($html === '') return '';

  $allowed = '<p><br><ul><ol><li><strong><b><em><i><a><code><pre><blockquote><h1><h2><h3><h4><img><figure><figcaption><span>';

  $html = preg_replace('/\s+on\w+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
  // preserve safe color styles on spans, strip other styles
  $html = preg_replace_callback('/<span\s+[^>]*style\s*=\s*["\']([^"\']*)["\'][^>]*>/i', function($m){
    $style = $m[1];
    // Only allow color property with hex colors (safe)
    if (preg_match('~^color\s*:\s*#[0-9a-fA-F]{6}\s*(?:;)?$~i', $style)) {
      return $m[0];
    }
    return preg_replace('/\s+style\s*=\s*["\'][^"\']*["\']/i', '', $m[0]);
  }, $html);

  $html = preg_replace_callback('/<a\b([^>]*)>/i', function($m){
    $attrs = $m[1];
    $href = '';
    if (preg_match('/\bhref\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $attrs, $hm)) {
      $href = html_entity_decode($hm[2] ?? $hm[3] ?? $hm[4] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $ok = preg_match('~^(https?:|mailto:|#)~i', $href);
    $safeHref = $ok ? e($href) : '#';
    return '<a href="'.$safeHref.'" rel="nofollow noopener" target="_blank">';
  }, $html);

  $html = preg_replace_callback('/<img\b([^>]*)>/i', function($m){
    $attrs = $m[1];
    $src = '';
    if (preg_match('/\bsrc\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $attrs, $sm)) {
      $src = html_entity_decode($sm[2] ?? $sm[3] ?? $sm[4] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    if (!preg_match('~^https?://~i', $src)) return '';
    $pu = parse_url($src);
    if (!$pu || empty($pu['host']) || !is_allowed_image_host($pu['host'])) return '';
    $alt = '';
    if (preg_match('/\balt\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $attrs, $am)) {
      $alt = e(html_entity_decode($am[2] ?? $am[3] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    return '<img src="'.e($src).'" alt="'.$alt.'" loading="lazy">';
  }, $html);

  $html = strip_tags($html, $allowed);
  return $html;
}

/* ===== MARKDOWN CONVERSION ===== */

function markdownish_to_html(string $txt): string {
  $txt = str_replace(["\r\n","\r"], "\n", trim($txt));
  if ($txt === '') return '';

  if (strpos($txt, "\n") === false && preg_match('/\*\s+/', $txt)) {
    $txt = preg_replace('/\s*\*\s+/', "\n* ", $txt);
  }

  $lines = explode("\n", $txt);
  $out = [];
  $inList = false;
  $para = [];

  $flush_para = function() use (&$out, &$para){
    if (!$para) return;
    $p = implode("\n", $para);
    $p = e($p);
    $p = nl2br($p, false);
    $out[] = "<p>{$p}</p>";
    $para = [];
  };
  
  $close_list = function() use (&$out, &$inList){
    if ($inList) { $out[] = '</ul>'; $inList = false; }
  };

  foreach ($lines as $line){
    if (preg_match('/^\s*[\*\-]\s+(.+)$/', $line, $m)) {
      $flush_para();
      if (!$inList){ $out[] = '<ul>'; $inList = true; }
      $item = e($m[1]);
      $item = preg_replace('/\*\*(.+?)\*\*/','<strong>$1</strong>', $item);
      $item = preg_replace('/\*(.+?)\*/','<em>$1</em>', $item);
      $out[] = '<li>'.$item.'</li>';
      continue;
    }
    if (trim($line) === '') { $close_list(); $flush_para(); continue; }
    $close_list(); $para[] = $line;
  }
  $close_list(); $flush_para();
  return implode("\n", $out);
}

/* ===== IMAGE EMBEDDING ===== */

function embed_images(string $html): string {
  if ($html === '') return '';

  $html = preg_replace_callback('~\[(?:img|image)\]\s*(https?://[^\s\]]+)\s*\[/(?:img|image)\]~i', function($m){
    $fig = build_figure_for_url($m[1]);
    return $fig !== '' ? $fig : e($m[1]);
  }, $html);

  $html = preg_replace_callback('~!\[([^\]]*)\]\((https?://[^)]+)\)~', function($m){
    $fig = build_figure_for_url($m[2]);
    return $fig !== '' ? $fig : e($m[0]);
  }, $html);

  $html = preg_replace_callback('~<img\s+[^>]*src\s*=\s*(["\']?)(https?://[^\s"\'>\)]+)\1[^>]*/?>~i', function($m){
    $fig = build_figure_for_url($m[2]);
    return $fig !== '' ? $fig : '';
  }, $html);

  $html = preg_replace_callback('~<a\s+[^>]*href\s*=\s*(["\']?)(https?://[^\s"\'>\)]+)\1[^>]*>([^<]*)</a>~is', function($m){
    $url = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $fig = build_figure_for_url($url);
    return $fig !== '' ? $fig : $m[0];
  }, $html);

  $html = preg_replace_callback('~(^|>)([^<]*?)(https?://[^\s<>\)]+)([^<]*)($|<)~m', function($m){
    $before = $m[1];
    $prefix = $m[2];
    $url = $m[3];
    $suffix = $m[4];
    $after = $m[5];
    
    if (preg_match('~["\']$~', $prefix)) return $m[0];
    
    $fig = build_figure_for_url($url);
    if ($fig !== '') {
      return $before . $prefix . $fig . $suffix . $after;
    }
    return $m[0];
  }, $html);

  return $html;
}

/* ===== BUILD PREVIEW ===== */

function is_probably_html(string $s): bool {
  return (bool)preg_match('~<\s*(a|p|br|ul|ol|li|h[1-6]|div|img|figure)\b~i', $s);
}

function html_to_preview_text(string $html): string {
  if ($html === '') return '';
  $html = str_replace(["\r\n","\r"], "\n", $html);
  $html = preg_replace('~<\s*br\s*/?\s*>~i', "\n", $html);
  $html = preg_replace('~<\s*/\s*(p|h[1-6]|div)\s*>~i', "\n\n", $html);
  $html = preg_replace('~<\s*li\s*>~i', "• ", $html);
  $html = str_ireplace('&nbsp;', ' ', $html);
  $text = strip_tags($html);
  $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  $text = preg_replace("/\n{3,}/", "\n\n", $text);
  return trim($text);
}

function build_preview_html(string $content): string {
  if ($content === '') return '';

  $src = str_replace(["\\r\\n","\\n","\\r"], "\n", $content);
  $src = trim($src);
  if ($src === '') return '';

  if (is_probably_html($src)) {
    $html = embed_images($src);
    // Parse WoW color codes before sanitization
    if (function_exists('parse_wow_colors')) {
      $html = parse_wow_colors($html);
    } elseif (strpos($html, '|cff') !== false) {
      // Inline fallback parser
      $html = str_replace(['&#124;', '&pipe;', '&#x7C;'], '|', $html);
      $html = preg_replace_callback('~\|cff([0-9a-fA-F]{6})(.*?)\|r~s', function($m) {
        return '<span style="color: #' . strtolower($m[1]) . ';">' . $m[2] . '</span>';
      }, $html);
    }
    return sanitize_html($html);
  }

  $html = markdownish_to_html($src);
  $html = embed_images($html);
  // Parse WoW color codes before sanitization
  if (function_exists('parse_wow_colors')) {
    $html = parse_wow_colors($html);
  } elseif (strpos($html, '|cff') !== false) {
    // Inline fallback parser
    $html = str_replace(['&#124;', '&pipe;', '&#x7C;'], '|', $html);
    $html = preg_replace_callback('~\|cff([0-9a-fA-F]{6})(.*?)\|r~s', function($m) {
      return '<span style="color: #' . strtolower($m[1]) . ';">' . $m[2] . '</span>';
    }, $html);
  }
  return sanitize_html($html);
}

function build_teaser_html(string $content): string {
  if ($content === '') return '';

  $src = str_replace(["\\r\\n","\\n","\\r"], "\n", $content);
  $src = trim($src);

  if (is_probably_html($src)) {
    $clean = preg_replace('~<figure.*?</figure>~is', '', $src);
    $clean = preg_replace('~<a[^>]+>.*?</a>~is', '', $clean);
    // Parse WoW colors before extracting text
    if (function_exists('parse_wow_colors')) {
      $clean = parse_wow_colors($clean);
    } elseif (strpos($clean, '|cff') !== false) {
      // Inline fallback parser
      $clean = str_replace(['&#124;', '&pipe;', '&#x7C;'], '|', $clean);
      $clean = preg_replace_callback('~\|cff([0-9a-fA-F]{6})(.*?)\|r~s', function($m) {
        return '<span style="color: #' . strtolower($m[1]) . ';">' . $m[2] . '</span>';
      }, $clean);
    }
    $text = html_to_preview_text($clean);
  } else {
    $lines = explode("\n", $src);
    $filtered = [];
    foreach ($lines as $line) {
      $line = trim($line);
      if ($line === '' || preg_match('~^https?://~i', $line)) continue;
      $line = preg_replace('~https?://\S+~', '', $line);
      if (trim($line) !== '') $filtered[] = $line;
    }
    $text = html_to_preview_text(markdownish_to_html(implode("\n", $filtered)));
  }

  $excerpt_text = excerpt($text, 160);
  return $excerpt_text !== '' ? '<span>'.e($excerpt_text).'</span>' : '';
}

function build_excerpt_text_for_readtime(string $content): string {
  if ($content === '') return '';
  $src = str_replace(["\\r\\n","\\n","\\r"], "\n", $content);
  $src = trim($src);

  if (is_probably_html($src)) {
    $clean = preg_replace('~<figure.*?</figure>~is', '', $src);
    return html_to_preview_text($clean);
  }

  $lines = explode("\n", $src);
  $filtered = [];
  foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || preg_match('~^https?://~i', $line)) continue;
    $line = preg_replace('~https?://\S+~', '', $line);
    if (trim($line) !== '') $filtered[] = $line;
  }
  $src = implode("\n", $filtered);
  return html_to_preview_text(markdownish_to_html($src));
}

$readTime = function(string $content, int $wpm=220): string {
  $words = max(1, str_word_count(build_excerpt_text_for_readtime($content)));
  $mins = max(1, (int)ceil($words / $wpm));
  return $mins . ' min';
};
?>
<section class="container px-4 pt-10" id="news">

  <style>
    .news-card > a { position: relative; z-index: 1; }
    .news-card .card-radial { pointer-events: none; }
    .news-card .toggle-row { position: relative; z-index: 20; pointer-events: auto; }
    .news-card .toggle-row[hidden] { display: none !important; }

    #news .teaser p { margin: .4em 0; }
    #news .teaser ul { margin: .4em 1.2em; padding-left: 1.2em; list-style: disc !important; }
    #news .teaser li { margin: .2em 0; }

    #news .news-body p{ margin: .5em 0; }
    #news .news-body ul{ margin: .5em 1.2em; padding-left: 1.2em; list-style: disc !important; }
    #news .news-body li{ margin: .25em 0; }

    #news .news-body[data-collapsed="true"]{
      max-height: 6em; overflow: hidden; position: relative;
    }
    #news .news-body[data-collapsed="true"]::after{
      content:""; position:absolute; left:0; right:0; bottom:0; height: 2em;
      background: linear-gradient(180deg, transparent, rgba(5,10,7,.78)); pointer-events:none;
    }

    #news .news-body .news-embed {
      margin: 0.75rem 0 1rem !important;
      border-radius: 0.75rem !important;
      background: linear-gradient(135deg, rgba(52, 211, 153, 0.2), rgba(16, 185, 129, 0.12)) !important;
      padding: 3px !important;
      display: block !important;
      overflow: hidden !important;
    }

    #news .news-body .news-embed img {
      max-width: 100% !important;
      height: auto !important;
      display: block !important;
      border-radius: 0.6rem !important;
      box-shadow: 
        0 2px 8px rgba(0, 0, 0, 0.3),
        0 8px 20px rgba(0, 0, 0, 0.4),
        0 16px 40px rgba(0, 0, 0, 0.5),
        inset 0 1px 0 rgba(255, 255, 255, 0.1) !important;
      transition: all 0.25s ease !important;
      transform: scale(1) !important;
    }

    #news .news-body .news-embed img:hover {
      transform: scale(1.02) translateY(-3px) !important;
      box-shadow: 
        0 4px 12px rgba(0, 0, 0, 0.4),
        0 12px 28px rgba(0, 0, 0, 0.5),
        0 20px 50px rgba(0, 0, 0, 0.6),
        inset 0 1px 0 rgba(255, 255, 255, 0.12) !important;
    }

    #news .news-body figure + p { margin-top: .75rem; }
    
    #news .news-body a {
      color: #34d399;
      text-decoration: underline;
      text-underline-offset: 2px;
    }
    #news .news-body a:hover { color: #6ee7b7; }
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
        $contentMerged = trim((string)($n['content'] ?? ''));
        if ($contentMerged === '') $contentMerged = trim((string)($n['content_preview'] ?? ''));
        if ($contentMerged === '') $contentMerged = trim((string)($n['excerpt'] ?? ''));

        $subtitle = trim((string)($n['subtitle'] ?? $n['sub_title'] ?? ''));
        $showSubtitle = ($subtitle !== '');

        $teaserHtml = build_teaser_html($contentMerged);
        $hasTeaser = ($teaserHtml !== '');

        $previewHtml = build_preview_html($contentMerged);
        $hasPreview = ($previewHtml !== '');

        $slug = (string)($n['slug'] ?? ('n-'.$idx));
        $bodyId = 'news-body-'.preg_replace('~[^a-z0-9\-]+~i', '-', $slug);
        $href = $slug !== '' ? '/news/'.rawurlencode($slug) : '#';

        $readTimeText = $readTime($contentMerged);
        $author = trim((string)($n['author'] ?? ''));
      ?>
      <article class="rough-card rough-card-hover group overflow-hidden news-card">
        <a href="<?= e($href) ?>" class="block focus:outline-none">
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
            <div class="flex items-center gap-2 text-xs text-neutral-400 flex-wrap">
              <?php if (empty($n['cover_url'])): ?>
                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] border border-amber-900/40 bg-amber-900/10 text-amber-200">News</span>
              <?php endif; ?>
              <?php if (!empty($n['published_at'])): ?>
                <span><?= e(format_date($n['published_at'])) ?></span>
                <span class="opacity-40">•</span>
              <?php endif; ?>
              <span><?= e($readTimeText) ?></span>
              <?php if ($author !== ''): ?>
                <span class="opacity-40 ml-auto">•</span>
                <span class="author-chip is-sheen-periodic"><?= e($author) ?></span>
              <?php endif; ?>
            </div>

            <h3 class="mt-2 font-semibold text-[18px] group-hover:text-ember-500 transition clamp-2">
              <?php
                $title = (string)($n['title'] ?? '');
                // Parse WoW colors in title
                if (strpos($title, '|cff') !== false) {
                  $title = str_replace(['&#124;', '&pipe;', '&#x7C;'], '|', $title);
                  $title = preg_replace_callback('~\|cff([0-9a-fA-F]{6})(.*?)\|r~s', function($m) {
                    return '<span style="color: #' . strtolower($m[1]) . ';">' . htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8') . '</span>';
                  }, $title);
                  echo $title;
                } else {
                  echo e($title);
                }
              ?>
            </h3>

            <?php if ($showSubtitle): ?>
              <p class="mt-1 text-sm text-neutral-400 clamp-2"><?php
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
            <?php endif; ?>

            <?php if ($hasTeaser): ?>
              <div class="mt-2 text-sm text-neutral-300 teaser">
                <?= $teaserHtml ?>
              </div>
            <?php endif; ?>
          </div>
          <span class="card-radial"></span>
        </a>

        <?php if ($hasPreview): ?>
          <div class="px-5 pb-5">
            <div id="<?= e($bodyId) ?>" class="news-body mt-1 text-sm text-neutral-300" data-collapsed="true">
              <?= $previewHtml ?>
            </div>

            <div class="toggle-row">
              <button type="button" class="mt-3 action-chip action-chip--vote blockwide" data-news-toggle="#<?= e($bodyId) ?>" aria-controls="<?= e($bodyId) ?>" aria-expanded="false">
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

<script>
(function(){
  function setup(btn){
    var sel = btn.getAttribute('data-news-toggle');
    var body = sel && document.querySelector(sel);
    var row = btn.closest('.toggle-row');
    if (!body || !row) { if (row) row.hidden = true; return; }

    var COLLAPSED = 64;
    body.style.overflow = 'hidden';
    body.style.transition = 'max-height 220ms ease';
    body.style.willChange = 'max-height';
    body.style.maxHeight = COLLAPSED + 'px';
    body.setAttribute('data-collapsed','true');

    function needsToggle(){ return body.scrollHeight > body.clientHeight + 1; }
    function set(expanded){
      btn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      body.setAttribute('data-collapsed', expanded ? 'false' : 'true');
      if (expanded) {
        body.style.maxHeight = body.scrollHeight + 'px';
        btn.textContent = 'Show less';
      } else {
        body.style.maxHeight = COLLAPSED + 'px';
        btn.textContent = 'Read more';
      }
    }

    row.hidden = !needsToggle();
    set(false);

    btn.addEventListener('click', function(ev){
      ev.preventDefault();
      ev.stopPropagation();
      set(btn.getAttribute('aria-expanded') !== 'true');
    });

    var t;
    window.addEventListener('resize', function(){
      clearTimeout(t);
      t = setTimeout(function(){
        var expanded = btn.getAttribute('aria-expanded') === 'true';
        if (expanded) body.style.maxHeight = body.scrollHeight + 'px';
        else row.hidden = !needsToggle();
      }, 120);
    }, { passive: true });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function(){
      document.querySelectorAll('[data-news-toggle]').forEach(setup);
    });
  } else {
    document.querySelectorAll('[data-news-toggle]').forEach(setup);
  }
})();
</script>