<?php
/**
 * Home page — Hero, dev banner, modules, and inline-expandable news
 * - Dev banner controlled by .env: HOMEPAGE_DEV_BANNER / HOMEPAGE_DEV_HEADLINE / HOMEPAGE_DEV_TEXT / HOMEPAGE_DEV_PERKS
 * - Banner sits higher, square edges, strong emerald identity
 * - Hides "Read more" when there isn't enough content to expand
 */

declare(strict_types=1);
require_once __DIR__ . '/../app/modules_view.php';

/* Fallback helpers */
if (!function_exists('e')) { function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('format_date')) { function format_date($s){ return $s ? date('M j, Y', strtotime((string)$s)) : ''; } }
if (!function_exists('base_url')) { function base_url(string $path=''){ return '/' . ltrim($path, '/'); } }
if (!function_exists('excerpt')) { function excerpt(string $s, int $n=160){ $s=trim($s); return mb_strlen($s)>$n? mb_substr($s,0,$n-1).'…' : $s; } }
function textify($s){ return trim((string)$s); }

/* .env helper (init.php also defines envv; keep local fallback) */
if (!function_exists('envv')) {
  function envv(string $key, $default = null) {
    $v = $_ENV[$key] ?? getenv($key);
    return ($v === false || $v === null || $v === '') ? $default : $v;
  }
}

/* ---------- Minimal, collision-free helpers for home news cards ---------- */

/** True if string likely already contains HTML tags (very lenient). */
if (!function_exists('home_news_is_html')) {
  function home_news_is_html(string $s): bool {
    if (strpos($s, '<') === false || strpos($s, '>') === false) return false;
    return (bool)preg_match('~<\s*(p|br|ul|ol|li|div|section|article|h[1-6]|strong|em|b|i|a|pre|code|blockquote)\b~i', $s);
  }
}

/** Autolink already-escaped text (https://… / www.…), unique name to avoid collisions */
if (!function_exists('home_news_autolink')) {
  function home_news_autolink(string $escaped): string {
    return preg_replace_callback(
      '~(^|[\s\(\[\{>])((?:https?://|www\.)[^\s<>"\']+?)([)\]\}\.,!?;:]*)(?=\s|$)~i',
      function($m){
        $lead  = $m[1] ?? '';
        $vis   = $m[2];                         // visible (already escaped)
        $trail = $m[3] ?? '';
        $hrefRaw = (stripos($vis, 'www.') === 0) ? ('https://' . $vis) : $vis;
        $href = htmlspecialchars($hrefRaw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return $lead.'<a href="'.$href.'" target="_blank" rel="nofollow noopener noreferrer">'.$vis.'</a>'.$trail;
      },
      $escaped
    );
  }
}

/** Check if URL is an allowed image host and looks like an image */
if (!function_exists('home_news_is_image_url')) {
  function home_news_is_image_url(string $url): bool {
    $parts = parse_url($url);
    if (!$parts || empty($parts['host'])) return false;
    
    $host = strtolower($parts['host']);
    $path = $parts['path'] ?? '';
    
    // Allowed image hosts
    $allowed = ['i.imgur.com', 'imgur.com', 'www.imgur.com', 'i.redd.it', 'preview.redd.it', 
            'media.discordapp.net', 'cdn.discordapp.com', 'images.unsplash.com', 'pbs.twimg.com', 
            'freeimage.host', 'www.freeimage.host', 'iili.io', 'media.tenor.com', 'media1.tenor.com'];
    
    if (!in_array($host, $allowed, true)) return false;
    
    // Check if path ends with image extension OR is iili.io
    if ($host === 'iili.io') return true;
    return (bool)preg_match('~\.(?:png|jpe?g|gif|webp|avif)(?:\?.*)?$~i', $path);
  }
}

/** Convert plaintext into HTML paragraphs + lists + embedded images */
if (!function_exists('home_news_plain_to_html')) {
  function home_news_plain_to_html(string $txt): string {
    if ($txt === '') return '';

    // Normalize both real and literal escaped newlines
    $txt = str_replace(["\r\n","\r"], "\n", $txt);
    $txt = str_replace(["\\r\\n","\\n","\\r"], "\n", $txt);
    $txt = trim($txt);

    // If it's flat but includes "* " bullets, reinsert newlines before bullets
    if (strpos($txt, "\n") === false && preg_match('/\*\s+/', $txt)) {
      $txt = preg_replace('/\s*\*\s+/', "\n* ", $txt);
    }

    $lines   = explode("\n", $txt);
    $out     = [];
    $inList  = false;
    $paraBuf = [];

    $flush_para = function() use (&$out,&$paraBuf){
      if (!$paraBuf) return;
      $p = implode("\n", $paraBuf);
      $p = e($p);                        // escape
      $p = home_news_autolink($p);       // autolink
      $p = nl2br($p, false);             // keep single line breaks inside paragraph
      $out[] = "<p>{$p}</p>";
      $paraBuf = [];
    };
    $close_list = function() use (&$out,&$inList){
      if ($inList) { $out[] = '</ul>'; $inList = false; }
    };

    foreach ($lines as $line) {
      $line_trimmed = trim($line);
      
      // Check if line is JUST a URL (bare image URL) - allow any HTTPS URL since only owners upload
      if (preg_match('~^(https?://\S+)$~i', $line_trimmed, $m)) {
        $url = $m[1];
        $flush_para();
        $close_list();
        $out[] = '<figure class="news-embed"><img src="'.e($url).'" alt="Embedded image" loading="lazy"></figure>';
        continue;
      }
      
      // Bullet like "* item" or "- item"
      if (preg_match('/^\s*[\*\-]\s+(.+)$/', $line, $m)) {
        $flush_para();
        if (!$inList) { $out[] = '<ul>'; $inList = true; }
        $item = e($m[1]);                // escape item
        $item = home_news_autolink($item); // autolink
        // micro inline: **bold**, *italic*
        $item = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $item);
        $item = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $item);
        $out[] = "<li>{$item}</li>";
        continue;
      }

      // Blank line => paragraph boundary
      if ($line_trimmed === '') { $close_list(); $flush_para(); continue; }

      // Normal text -> paragraph buffer
      $close_list();
      $paraBuf[] = $line;
    }

    $close_list();
    $flush_para();

    return implode("\n", $out);
  }
}

/** Convert plaintext like your DB example into HTML paragraphs + <ul><li> bullets (with autolink). */
if (!function_exists('home_news_plain_to_html')) {
  function home_news_plain_to_html(string $txt): string {
    if ($txt === '') return '';

    // Normalize both real and literal escaped newlines
    $txt = str_replace(["\r\n","\r"], "\n", $txt);
    $txt = str_replace(["\\r\\n","\\n","\\r"], "\n", $txt);
    $txt = trim($txt);

    // If it's flat but includes "* " bullets, reinsert newlines before bullets
    if (strpos($txt, "\n") === false && preg_match('/\*\s+/', $txt)) {
      $txt = preg_replace('/\s*\*\s+/', "\n* ", $txt);
    }

    $lines   = explode("\n", $txt);
    $out     = [];
    $inList  = false;
    $paraBuf = [];

    $flush_para = function() use (&$out,&$paraBuf){
      if (!$paraBuf) return;
      $p = implode("\n", $paraBuf);
      $p = e($p);                        // escape
      $p = home_news_autolink($p);       // autolink
      $p = nl2br($p, false);             // keep single line breaks inside paragraph
      $out[] = "<p>{$p}</p>";
      $paraBuf = [];
    };
    $close_list = function() use (&$out,&$inList){
      if ($inList) { $out[] = '</ul>'; $inList = false; }
    };

    foreach ($lines as $line) {
      // Bullet like "* item" or "- item"
      if (preg_match('/^\s*[\*\-]\s+(.+)$/', $line, $m)) {
        $flush_para();
        if (!$inList) { $out[] = '<ul>'; $inList = true; }
        $item = e($m[1]);                // escape item
        $item = home_news_autolink($item); // autolink
        // micro inline: **bold**, *italic*
        $item = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $item);
        $item = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $item);
        $out[] = "<li>{$item}</li>";
        continue;
      }

      // Blank line => paragraph boundary
      if (trim($line) === '') { $close_list(); $flush_para(); continue; }

      // Normal text -> paragraph buffer
      $close_list();
      $paraBuf[] = $line;
    }

    $close_list();
    $flush_para();

    return implode("\n", $out);
  }
}

$THEME = theme_current();

/* Dev banner flags (do NOT affect redirects) */
$SHOW_DEV_BANNER   = (bool)filter_var(envv('HOMEPAGE_DEV_BANNER', false), FILTER_VALIDATE_BOOLEAN);
$DEV_BANNER_HEAD   = (string)envv('HOMEPAGE_DEV_HEADLINE', 'Server in active development');
$DEV_BANNER_SUB    = (string)envv('HOMEPAGE_DEV_TEXT', 'Register today to reserve your name and get notified when we go live. Pre-register rewards await at launch.');
$DEV_BANNER_PERKS  = (string)envv('HOMEPAGE_DEV_PERKS', 'Launch rewards, News & patch notes');
$DEV_PERK_LIST     = array_values(array_filter(array_map('trim', explode(',', $DEV_BANNER_PERKS))));

/* News data */
if (!function_exists('news_latest')) {
  if (function_exists('require_repo')) require_repo('news_repo');
}
$INITIAL_SHOW = 5;
$posts = function_exists('news_latest') ? news_latest($pdo, 20) : [];

/* Discord widget config */
$discordWidgetId = '';
foreach ([
  $config['discord_widget_id']   ?? null,
  $config['discord_server_id']   ?? null,
  $config['discord_guild_id']    ?? null,
  $config['discord']['widget_id']?? null,
  $_ENV['DISCORD_WIDGET_ID']     ?? null,
  $_ENV['DISCORD_SERVER_ID']     ?? null,
  $_ENV['DISCORD_GUILD_ID']      ?? null,
] as $cand) {
  $cand = $cand !== null ? preg_replace('/\D+/', '', (string)$cand) : '';
  if ($cand !== '') { $discordWidgetId = $cand; break; }
}
$discordInviteUrl = '';
foreach ([
  $config['discord_invite_url'] ?? null,
  $config['discord_invite']     ?? null,
  $_ENV['DISCORD_INVITE_URL']   ?? null,
  $_ENV['DISCORD_INVITE']       ?? null,
] as $cand) {
  $cand = $cand !== null ? trim((string)$cand) : '';
  if ($cand !== '' && preg_match('#^https?://#i', $cand)) { $discordInviteUrl = $cand; break; }
}
?>

<?php if ($SHOW_DEV_BANNER): ?>
<!-- ===== DEV UI (namespaced, additive, safe) ===== -->
<style>
  .devui-hazard{position:relative;width:100%;margin-top:clamp(72px,5vh,96px);color:#fff;
    background:repeating-linear-gradient(135deg,
      rgba(185,28,28,.98) 0, rgba(185,28,28,.98) 16px,
      rgba(15,15,15,.98) 16px, rgba(15,15,15,.98) 32px);
	  box-shadow:0 6px 16px rgba(0,0,0,.85);}
  .devui-hazard-inner{display:flex;gap:.9rem;align-items:center;justify-content:center;
    padding:1.3rem 1rem;font-weight:900;letter-spacing:.08em;text-transform:uppercase;
    text-shadow:0 2px 10px rgba(0,0,0,.65);}
  .devui-badge{display:inline-flex;align-items:center;gap:.5rem;padding:.35rem .55rem;
    border:1px solid rgba(255,255,255,.25);background:rgba(24,5,5,.55);backdrop-filter:blur(4px);
    font-size:.85rem;border-radius:0;}
  .devui-dot{width:11px;height:11px;border-radius:50%;background:#ef4444;box-shadow:0 0 0 2px rgba(255,255,255,.18);
    animation:devui-pulse 1.6s ease-in-out infinite;}
  @keyframes devui-pulse{0%,100%{transform:scale(1);opacity:1}50%{transform:scale(.66);opacity:.75}}
  @media (prefers-reduced-motion:reduce){.devui-dot{animation:none}}

  /* Entry modal — square corners, namespaced to avoid collisions */
  .devui-modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.75);backdrop-filter:blur(4px);
    display:none;align-items:center;justify-content:center;z-index:10001;}
  .devui-modal{width:min(680px,calc(100vw - 2rem));
    background:linear-gradient(180deg,rgba(255,255,255,.04),rgba(0,0,0,.55)),var(--card-bg,#0b1310);
    border:1px solid rgba(255,255,255,.12);box-shadow:0 24px 80px rgba(0,0,0,.6);color:#eaf6ef;border-radius:0;padding:24px;}
  .devui-modal h3{margin:0 0 6px 0;font-size:1.5rem;font-weight:900}
  .devui-modal p{margin:8px 0 0 0;color:#b8c9bf}
  .devui-modal .actions{display:flex;gap:.6rem;margin-top:18px;flex-wrap:wrap}
</style>

<div class="devui-hazard" role="status" aria-live="polite">
  <div class="devui-hazard-inner">
    <span class="devui-badge"><span class="devui-dot" aria-hidden="true"></span> IN DEVELOPMENT</span>
    <span class="sr">Server is in active development</span>
  </div>
</div>

<div class="devui-modal-backdrop" id="devui-modal">
  <div class="devui-modal" role="dialog" aria-modal="true" aria-labelledby="devuiModalTitle">
    <h3 id="devuiModalTitle">Heads-up: This server is in active development</h3>
    <p>We’re actively building Thorium. Expect frequent updates and visual tweaks while we prepare for launch.</p>
    <div class="actions">
      <!-- Using your theme buttons; no extra CSS applied to .btn -->
      <a class="btn btn-warm" href="<?= e(base_url('register')) ?>">Create account</a>
      <?php if ($discordInviteUrl !== ''): ?>
        <a class="btn btn-ghost" href="<?= e($discordInviteUrl) ?>" target="_blank" rel="noopener">Join Discord</a>
      <?php endif; ?>
      <button class="btn btn-ghost" type="button" id="devuiModalDismiss">I understand</button>
    </div>
  </div>
</div>

<script>
(function(){
  var KEY='thorium_dev_seen_session';
  try{
    if(!sessionStorage.getItem(KEY)){
      var modal=document.getElementById('devui-modal');
      var btn=document.getElementById('devuiModalDismiss');
      if(modal){
        modal.style.display='flex';
        var close=function(){ modal.style.display='none'; sessionStorage.setItem(KEY,'1'); };
        if(btn) btn.addEventListener('click', close);
        modal.addEventListener('click', function(ev){ if(ev.target===modal) close(); });
        document.addEventListener('keydown', function(ev){ if(ev.key==='Escape') close(); }, {once:true});
      }
    }
  }catch(e){}
})();
</script>
<!-- ===== END DEV UI ===== -->
<?php endif; ?>

<!-- Dev banner styles (scoped to #home) -->
<style>
  /* Position higher, responsive */
  #home .dev-banner-wrap{ transform: translateY(-140px); }
  @media (min-width: 640px){  #home .dev-banner-wrap{ transform: translateY(-176px); } }
  @media (min-width: 1024px){ #home .dev-banner-wrap{ transform: translateY(-198px); } }

  /* Layout */
  #home .dev-banner{
    position:relative; isolation:isolate;
    display:grid; grid-template-columns: 1fr auto; align-items:center; gap: 1.1rem 1.4rem;
    margin: 0 auto 12px; max-width: 1100px;
    padding: 1.1rem 1.3rem;
    border:1px solid var(--card-border);
    border-radius:0;
    background:
      linear-gradient(180deg, var(--white-04), var(--shadow-ink-120)),
      var(--card-bg-2);
    box-shadow:
      inset 0 1px 0 var(--white-06),
      0 12px 28px -20px var(--shadow-ink-600);
    color: var(--ink-100);
  }

  /* Slim accent bars (top & bottom) */
  #home .dev-banner::before,
  #home .dev-banner::after{
    content:""; position:absolute; left:-1px; right:-1px; height:3px;
    background: linear-gradient(90deg, var(--brand-700), var(--brand-500), var(--brand-400));
    box-shadow: inset 0 0 0 1px var(--white-06);
    pointer-events:none;
  }
  #home .dev-banner::before{ top:-1px; }
  #home .dev-banner::after { bottom:-1px; }

  /* Left column */
  #home .dev-left{ text-align:left; }

  /* Eyebrow */
  #home .dev-eyebrow{
    display:inline-flex; align-items:center; gap:.5rem;
    font-size:12px; font-weight:800; letter-spacing:.14em; text-transform:uppercase;
    color: var(--text-muted);
    margin-bottom:.25rem;
  }
  #home .dev-dot{
    width: 12px; height: 12px; border-radius:0; flex:none;
    background: var(--brand-400);
    box-shadow: 0 0 0 1px var(--white-20);
  }

  /* Headline + subcopy */
  #home .dev-headline{
    font-weight: 900;
    font-size: clamp(1.15rem, 2.4vw, 1.8rem);
    line-height: 1.05;
    letter-spacing: .01em;
    text-shadow: 0 1px 2px var(--shadow-ink-400);
  }
  #home .dev-sub{
    margin-top:.35rem;
    color: var(--text-muted);
    font-size: clamp(.98rem, 1.2vw, 1.06rem);
  }

  /* Perk chips */
  #home .dev-perks{
    display:flex; flex-wrap:wrap; gap:.4rem .5rem; margin-top:.65rem;
  }
  #home .dev-perks .perk{
    display:inline-flex; align-items:center; gap:.45rem;
    padding:.35rem .55rem;
    font-size:.82rem; font-weight:700; letter-spacing:.02em;
    border-radius:0;
    border:1px solid var(--border-strong);
    background: linear-gradient(180deg, var(--white-05), var(--shadow-ink-120)), var(--card-bg);
    color: var(--ink-100);
    box-shadow: inset 0 1px 0 var(--white-06);
  }
  #home .dev-perks .perk::before{
    content:""; width:10px; height:10px; border-radius:0;
    background: var(--brand-400);
    box-shadow: 0 0 0 1px var(--white-20);
  }

  /* CTA stack */
  #home .dev-right{
    display:flex; flex-direction:column; gap:.55rem; align-self:start;
  }
  #home .dev-cta, #home .dev-cta-alt{ min-width: 220px; justify-content:center; }
  #home .dev-cta-alt{ text-decoration:none; }

  @media (max-width: 639.98px){
    #home .dev-banner{ grid-template-columns: 1fr; }
    #home .dev-right{ flex-direction:row; gap:.5rem; }
    #home .dev-cta, #home .dev-cta-alt{ flex:1 1 0; min-width:0; }
  }
</style>

<section id="home" class="hero-emerald"
  data-hero-zoom=".6"
  data-hero-blur-factor="0.002"
  data-hero-follow="0.10"
  data-hero-max-blur="18"
  data-hero-fade-factor="0.25"
  data-hero-min-opacity="0.22"
  data-hero-src="<?= e(theme_asset_url('assets/BackgroundWebsite.png')) ?>">

  <img
    class="hero-img"
    src="<?= e(theme_asset_url('assets/BackgroundWebsite.png')) ?>"
    alt="Hero Background"
    decoding="async"
    fetchpriority="high"
    loading="eager"
  />

  <div class="container hero-inner max-w-6xl mx-auto px-6 text-center">

    <?php if ($SHOW_DEV_BANNER): ?>
      <div class="dev-banner-wrap animate-on-scroll visible" aria-live="polite">
        <div class="dev-banner">
          <div class="dev-left">
            <div class="dev-eyebrow"><span class="dev-dot"></span> Development Update</div>
            <div class="dev-headline"><?= e($DEV_BANNER_HEAD) ?></div>
            <div class="dev-sub"><?= e($DEV_BANNER_SUB) ?></div>
            <?php if (!empty($DEV_PERK_LIST)): ?>
              <div class="dev-perks">
                <?php foreach ($DEV_PERK_LIST as $perk): ?>
                  <span class="perk"><?= e($perk) ?></span>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
          <div class="dev-right">
            <a href="<?= e(base_url('register')) ?>" class="btn btn-grove dev-cta">Create Account</a>
            <?php if (module_enabled('news')): ?>
              <a href="#news" class="btn btn-ghost dev-cta-alt">Read Updates</a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <p class="kicker mb-3 animate-on-scroll">Sanctuary of the Emerald Forest</p>
    <h1 class="h-display hero-title text-5xl md:text-7xl font-bold leading-tight animate-on-scroll">
      Welcome to <span class="shimmer-text">Thorium</span>
      <span class="block title-ornate mt-3"></span>
    </h1>
    <p class="hero-sub text-lg md:text-xl mt-6 max-w-2xl mx-auto animate-on-scroll">
      Your new home and sanctuary.
    </p>
    <div class="mt-8 flex flex-col sm:flex-row gap-4 justify-center animate-on-scroll">
      <a href="<?= e(base_url('register')) ?>" class="btn btn-warm btn-lg">Begin Journey</a>
      <?php if (module_enabled('news')): ?>
        <a href="#news" class="btn btn-outline btn-lg">Latest News</a>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php if (module_enabled('announcements')): ?>
  <?php module_render('announcements'); ?>
<?php endif; ?>

<?php if (module_enabled('news')): ?>
<section id="news" class="py-16 relative scroll-target">
  <style>
    /* Collapsible rules scoped to #news */
    #news .news-body{ overflow:hidden; transition:max-height 220ms ease; }
    #news .news-body[data-collapsed="true"]{ max-height:64px; } /* ≈3 lines */
    #news .toggle-row[hidden]{ display:none!important; }

    /* Ensure paragraphs & bullets look right on the home cards */
    #news .news-body p { margin:.5em 0; }
    #news .news-body ul { margin:.5em 1.2em; padding-left:1.2em; list-style: disc; }
    #news .news-body li { margin:.25em 0; }

    /* Make links visibly clickable (subtitle + body) */
    #news .news-body a,
    #news .news-subtitle a { text-decoration: underline; }
	
	/* Make links visibly clickable (subtitle + body) */
#news {
  /* uses your theme vars with fallbacks */
  --news-link: var(--brand-400, #34d399);
  --news-link-hover: var(--brand-300, #6ee7b7);
  --news-link-visited: #2bbd8f;
}

#news .news-body a,
#news .news-subtitle a {
  color: var(--news-link);
  text-decoration: underline;
  text-underline-offset: 2px;
}

#news .news-body a:hover,
#news .news-subtitle a:hover {
  color: var(--news-link-hover);
}

#news .news-body a:visited,
#news .news-subtitle a:visited {
  color: var(--news-link-visited);
}

#news .news-body a:focus-visible,
#news .news-subtitle a:focus-visible {
  outline: 2px solid var(--news-link);
  outline-offset: 2px;
  border-radius: 2px;
}

  </style>

  <div class="container max-w-3xl mx-auto px-6">
    <div class="text-center mb-10 animate-on-scroll">
      <p class="kicker">Stay Updated</p>
      <h2 class="h-display text-4xl font-bold">Chronicles of the Grove</h2>
    </div>

    <?php if (!empty($posts)): ?>
      <div class="space-y-6 cards-deferred" data-news-list>
        <?php foreach ($posts as $i => $n): ?>
          <?php
            $title     = textify($n['title'] ?? '');
            $date      = format_date($n['published_at'] ?? null);
            $subtitle  = textify($n['subtitle'] ?? ($n['sub_title'] ?? ''));
            $author    = textify($n['author'] ?? '');
            $pinned    = (int)($n['pinned'] ?? 0);
            $isOlder   = ($i >= $INITIAL_SHOW);

            // Prefer preview -> content -> excerpt (so links in preview show on home)
            $rawPrev    = (string)($n['content_preview'] ?? '');
            $rawContent = (string)($n['content'] ?? '');
            $rawExcerpt = (string)($n['excerpt'] ?? '');
            $src = trim($rawPrev)    !== '' ? $rawPrev
                 : (trim($rawContent) !== '' ? $rawContent : $rawExcerpt);

            // If HTML -> use as-is; if plaintext -> convert (with autolink inside)
            $bodyHtml = home_news_is_html($src) ? $src : home_news_plain_to_html($src);
            // Parse WoW colors in body
            if (strpos($bodyHtml, '|cff') !== false) {
              $bodyHtml = str_replace(['&#124;', '&pipe;', '&#x7C;'], '|', $bodyHtml);
              $bodyHtml = preg_replace_callback('~\|cff([0-9a-fA-F]{6})(.*?)\|r~s', function($m) {
                return '<span style="color: #' . strtolower($m[1]) . ';">' . htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8') . '</span>';
              }, $bodyHtml);
            }
          ?>
          <article class="rough-card rough-card-hover p-5 animate-on-scroll <?= $isOlder ? 'hidden older-news' : '' ?> <?= $pinned ? 'pinned-post' : '' ?>" data-news-item>
            <div class="flex items-center gap-2 flex-wrap">
              <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] border border-amber-900/40 bg-amber-900/10 text-amber-200">News</span>
              <?php if ($date): ?><span class="text-xs text-neutral-400"><?= e($date) ?></span><?php endif; ?>
              <?php if ($pinned): ?>
                <div class="flex items-center gap-1 text-yellow-400" title="This post is pinned to the top">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                  <span class="text-xs font-medium">Pinned</span>
                </div>
              <?php endif; ?>
              <?php if ($author !== ''): ?>
                <span class="author-chip is-sheen-periodic ml-auto">
                  <svg width="12" height="12" viewBox="0 0 20 20" aria-hidden="true" class="opacity-80"><path fill="currentColor" d="M10 10a4 4 0 100-8 4 4 0 000 8zm-7 8a7 7 0 1114 0H3z"/></svg>
                  <?= e($author) ?>
                </span>
              <?php endif; ?>
            </div>

            <h3 class="mt-2 font-semibold text-[18px] group-hover:text-ember-500 transition"><?php
              // Parse WoW colors in title
              $title_display = (string)$title;
              if (strpos($title_display, '|cff') !== false) {
                $title_display = str_replace(['&#124;', '&pipe;', '&#x7C;'], '|', $title_display);
                $title_display = preg_replace_callback('~\|cff([0-9a-fA-F]{6})(.*?)\|r~s', function($m) {
                  return '<span style="color: #' . strtolower($m[1]) . ';">' . htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8') . '</span>';
                }, $title_display);
                echo $title_display;
              } else {
                echo e($title_display);
              }
            ?></h3>

            <?php if ($subtitle !== ''): ?>
              <!-- Subtitle autolinked so bare URLs click -->
              <p class="mt-1 text-sm text-neutral-400 news-subtitle">
                <?php
                  // Parse WoW colors in subtitle
                  $subtitle_display = (string)$subtitle;
                  if (strpos($subtitle_display, '|cff') !== false) {
                    $subtitle_display = str_replace(['&#124;', '&pipe;', '&#x7C;'], '|', $subtitle_display);
                    $subtitle_display = preg_replace_callback('~\|cff([0-9a-fA-F]{6})(.*?)\|r~s', function($m) {
                      return '<span style="color: #' . strtolower($m[1]) . ';">' . htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8') . '</span>';
                    }, $subtitle_display);
                    echo home_news_autolink($subtitle_display);
                  } else {
                    echo home_news_autolink(e($subtitle_display));
                  }
                ?>
              </p>
            <?php endif; ?>

            <?php if ($bodyHtml !== ''): ?>
              <div class="prose prose-invert mt-2 max-w-none">
                <div class="news-body" data-collapsed="true" data-measure>
                  <?= $bodyHtml ?>
                </div>
              </div>
              <div class="mt-3 toggle-row" hidden>
                <button class="btn-ghost btn-sm" data-expand type="button" aria-expanded="false">Read more</button>
              </div>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>

      <?php if (count($posts) > $INITIAL_SHOW): ?>
        <div class="text-center mt-8">
          <button class="btn btn-warm btn-lg" id="show-older" type="button">Show older posts</button>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <div class="rough-card p-6 text-center text-neutral-300">No news yet—check back soon.</div>
    <?php endif; ?>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const BASE_COLLAPSED_PX = 64; // fallback if CSS not yet readable

      function setupCard(card){
        const body = card.querySelector('[data-measure]');
        const row  = card.querySelector('.toggle-row');
        const btn  = card.querySelector('[data-expand]');
        if (!body || !row || !btn) return;

        // Ensure collapsed before measuring
        body.setAttribute('data-collapsed','true');

        function collapsedMax(){
          const mh = getComputedStyle(body).maxHeight;
          const v  = parseFloat(mh);
          return Number.isFinite(v) && v > 0 ? v : BASE_COLLAPSED_PX;
        }
        function needsToggle(){ return body.scrollHeight > collapsedMax() + 2; }

        function sync(){
          if (!needsToggle()){
            row.hidden = true; body.removeAttribute('data-collapsed'); body.style.maxHeight = '';
            btn.setAttribute('aria-expanded','false'); card.dataset.expanded = '0';
          } else {
            row.hidden = false; body.setAttribute('data-collapsed','true'); body.style.maxHeight = '';
          }
        }

        requestAnimationFrame(sync); setTimeout(sync, 200);

        btn.addEventListener('click', (ev) => {
          ev.preventDefault();
          const expanded = card.dataset.expanded === '1';
          if (expanded){
            body.setAttribute('data-collapsed','true'); body.style.maxHeight = '';
            btn.textContent = 'Read more'; btn.setAttribute('aria-expanded','false'); card.dataset.expanded = '0';
          } else {
            body.setAttribute('data-collapsed','false'); body.style.maxHeight = body.scrollHeight + 'px';
            btn.textContent = 'Show less'; btn.setAttribute('aria-expanded','true'); card.dataset.expanded = '1';
          }
        });

        let t;
        window.addEventListener('resize', () => {
          clearTimeout(t);
          t = setTimeout(() => {
            if (card.dataset.expanded === '1') body.style.maxHeight = body.scrollHeight + 'px';
            else sync();
          }, 120);
        }, { passive: true });
      }

      document.querySelectorAll('[data-news-item]').forEach(setupCard);

      const olderBtn = document.getElementById('show-older');
      if (olderBtn) {
        olderBtn.addEventListener('click', () => {
          document.querySelectorAll('.older-news').forEach(n => n.classList.remove('hidden'));
          olderBtn.remove();
        });
      }

      if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(() => {
          document.querySelectorAll('[data-news-item]').forEach((card) => {
            const row = card.querySelector('.toggle-row');
            const body = card.querySelector('[data-measure]');
            if (!row || !body) return;
            body.setAttribute('data-collapsed','true');
            const threshold = (parseFloat(getComputedStyle(body).maxHeight) || BASE_COLLAPSED_PX) + 2;
            row.hidden = !(body.scrollHeight > threshold);
            if (row.hidden) body.removeAttribute('data-collapsed');
          });
        });
      }
    });
  </script>
</section>
<?php endif; ?>

<!-- Feature modules (AFTER News) -->
<div class="cards-deferred"><?php module_render('features'); ?></div>
<div class="cards-deferred"><?php module_render('bloodmarks'); ?></div>
<div class="cards-deferred"><?php module_render('honorable_kills'); ?></div>

<section id="status" class="scroll-target">
  <div class="cards-deferred"><?php module_render('realms'); ?></div>
</section>

<!-- Discord (direct embed) -->
<section id="discord" class="py-16 scroll-target">
  <div class="container max-w-4xl mx-auto px-6">
    <div class="rough-card p-6">
      <div class="flex items-center justify-between flex-wrap gap-3">
        <h2 class="h-display text-2xl font-bold">Community Discord</h2>
        <?php if ($discordInviteUrl !== ''): ?>
          <a href="<?= e($discordInviteUrl) ?>" class="btn btn-warm" target="_blank" rel="noopener">Open in Discord</a>
        <?php endif; ?>
      </div>
      <div class="mt-5">
        <?php if ($discordWidgetId !== ''): ?>
          <iframe
            src="https://discord.com/widget?id=<?= e($discordWidgetId) ?>&theme=dark"
            width="100%" height="500" frameborder="0"
            allowtransparency="true" referrerpolicy="no-referrer" loading="lazy"
            style="border-radius:0; background:rgba(0,0,0,.2)"
          ></iframe>
        <?php else: ?>
          <div class="text-sm text-neutral-400">
            Set <code>$config['discord_widget_id']</code> (or <code>$_ENV['DISCORD_WIDGET_ID']</code>) to enable the embed.
            You can also provide <code>$config['discord_invite_url']</code> (or <code>$_ENV['DISCORD_INVITE_URL']</code>) for the button.
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<?php if (module_enabled('chat')): ?>
<section id="chat" class="scroll-target">
  <div class="cards-deferred"><?php module_render('chat'); ?></div>
</section>
<?php endif; ?>

<!-- CTA -->
<section class="py-20 relative">
  <div class="container max-w-4xl mx-auto px-6">
    <div class="rough-card p-10 text-center shine cards-deferred animate-on-scroll">
      <div class="section">
        <h2 class="h-display text-3xl font-bold mb-6">Answer the Call of the Emerald Forest</h2>
        <p class="text-xl muted mb-8 max-w-2xl mx-auto">Forge your legend. The Emerald Forest awaits.</p>
        <div class="flex flex-col sm:flex-row gap-4 justify-center">
          <a href="<?= e(base_url('register')) ?>" class="btn btn-warm btn-lg">Create Account</a>
        </div>
      </div>
    </div>
  </div>
</section>

<?php if (defined('DEBUG') && DEBUG): ?>
<script>console.log('Theme:', '<?= e($THEME) ?>');</script>
<?php endif; ?>
