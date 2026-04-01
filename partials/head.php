<?php
/**
 * partials/head.php
 * Local Tailwind + robust theme asset resolution + quiet console + optional lazy Discord widget.
 */

declare(strict_types=1);

/* ───────── Helpers ───────── */
if (!function_exists('e')) { function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('base_url')) { function base_url(string $p=''): string { return '/'.ltrim($p, '/'); } }
if (!function_exists('__thorium_root')) {
  function __thorium_root(): string {
    $dir = __DIR__;
    for ($i=0; $i<12; $i++) { if (is_dir($dir.'/app') && is_dir($dir.'/public')) return $dir; $p = dirname($dir); if ($p === $dir) break; $dir = $p; }
    return __DIR__;
  }
}
$ROOT   = __thorium_root();
$PUBLIC = $ROOT . '/public';

/* Origin (helps some iframe/self checks in CSP) */
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$ORIGIN = $scheme . '://' . $host;

/* ───────── Site title ───────── */
$site      = $config['site_name'] ?? 'Thorium WoW';
$fullTitle = ($title ?? '') ? e($title).' · '.e($site) : e($site);

/* ───────── Env flags ───────── */
$fogEnabled         = $_ENV['FOG_ENABLED']         ?? 'true';
$fogPreset          = $_ENV['FOG_PRESET']          ?? 'emerald_mist';
$fogOpacity         = $_ENV['FOG_OPACITY']         ?? '0.3';
$particlesEnabled   = $_ENV['PARTICLES_ENABLED']   ?? 'true';
$particlesType      = $_ENV['PARTICLES_TYPE']      ?? 'leaves';
$particlesDensity   = $_ENV['PARTICLES_DENSITY']   ?? 'medium';
$particlesSpeed     = $_ENV['PARTICLES_SPEED']     ?? 'slow';
$particlesColor     = $_ENV['PARTICLES_COLOR']     ?? 'auto';
$particlesDirection = $_ENV['PARTICLES_DIRECTION'] ?? 'down';
$particlesSwirl     = $_ENV['PARTICLES_SWIRL']     ?? 'false';
$particlesRotation  = $_ENV['PARTICLES_ROTATION']  ?? 'true';
$particlesWind      = $_ENV['PARTICLES_WIND']      ?? '0.8';
$particlesGravity   = $_ENV['PARTICLES_GRAVITY']   ?? '1.0';

$forceFogHome       = (($_ENV['FOG_FORCE_ON_HOME']       ?? 'false') === 'true');
$forceParticlesHome = (($_ENV['PARTICLES_FORCE_ON_HOME'] ?? 'false') === 'true');
$allowCFInsights    = (($_ENV['ALLOW_CF_INSIGHTS']       ?? 'false') === 'true');      // default OFF
$quietConsole       = (($_ENV['QUIET_THIRD_PARTY_WARNINGS'] ?? 'true') === 'true');   // default ON

/* ───────── Tailwind (local build) ───────── */
$twRel  = 'assets/app.min.css';
$twFs   = $PUBLIC . '/' . $twRel;
$twVer  = is_file($twFs) ? (string)@filemtime($twFs) : 'dev';
$twHref = base_url($twRel) . '?v=' . $twVer;

/* ───────── Theme resolver (static first, PHP fallback) ───────── */
$THEME_SLUG         = 'thorium-emeraldforest';
$themeStaticBaseFs  = $PUBLIC . '/themes/' . $THEME_SLUG;
$themeStaticBaseUrl = base_url('themes/' . $THEME_SLUG);
$theme_url_resolve = function (string $rel) use ($themeStaticBaseFs, $themeStaticBaseUrl, $twVer): string {
  $rel = ltrim($rel,'/');
  $fs  = $themeStaticBaseFs . '/' . $rel;
  if (is_file($fs)) {
    $v = (string)@filemtime($fs) ?: $twVer;
    return $themeStaticBaseUrl . '/' . $rel . '?v=' . $v;
  }
  if (function_exists('theme_asset_url')) {
    $url = theme_asset_url($rel);
    return $url . (strpos($url,'?')===false ? '?v=' : '&v=') . $twVer;
  }
  return $themeStaticBaseUrl . '/' . $rel . '?v=' . $twVer;
};

/* Theme assets */
$themeCss     = $theme_url_resolve('css/theme.css');
$navHeroJs    = $theme_url_resolve('js/nav-hero.js');
$themeJs      = $theme_url_resolve('js/theme.js');
$pageBgJs     = $theme_url_resolve('js/page-bg.js');
$fogLoaderJs  = $theme_url_resolve('js/fog-loader.js');
$partLoaderJs = $theme_url_resolve('js/particle-loader.js');
$clickCritJs  = $theme_url_resolve('js/trolls/clickcrit.js');
$bgImg        = $theme_url_resolve('assets/Backgroundv2.png');

/* ───────── Headers (CSP / Permissions-Policy) ───────── */
if (!headers_sent()) {
  if (function_exists('header_remove')) {
    @header_remove('Feature-Policy');
  }
  header("Permissions-Policy: geolocation=(), camera=(), microphone=(), payment=()");

$scriptSrc  = ["'self'","'unsafe-inline'",$ORIGIN,"https://cdnjs.cloudflare.com", "https://wow.zamimg.com", "https://www.wowhead.com"];
$connectSrc = ["'self'", "https://wow.zamimg.com", "https://wotlk.murlocvillage.com", "https://www.wowhead.com"];

  if ($allowCFInsights) {
    $scriptSrc[]  = "https://static.cloudflareinsights.com";
    $connectSrc[] = "https://static.cloudflareinsights.com";
    $scriptSrc[]  = "https://challenges.cloudflare.com";
    $scriptSrc[]  = "https://*.cloudflare.com";
    $connectSrc[] = "https://*.cloudflare.com";
  }

$csp = [
  "default-src 'self'",
  "base-uri 'self'",
  "object-src 'none'",
  "worker-src 'self' blob:",
  "script-src " . implode(' ', $scriptSrc),
  "style-src  'self' 'unsafe-inline' https://fonts.googleapis.com https://wow.zamimg.com",
  "img-src    'self' data: blob: https:",  // ← Added blob: here
  "font-src   'self' https://fonts.gstatic.com",
  "connect-src " . implode(' ', $connectSrc),
  "frame-src  https://discord.com https://*.discord.com https://*.discordapp.com https://challenges.cloudflare.com",
  "child-src  https://discord.com https://*.discord.com https://*.discordapp.com https://challenges.cloudflare.com",
];
  header('Content-Security-Policy: ' . implode('; ', $csp));
}
?>
<!doctype html>
<html lang="en" class="h-full">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
  <title><?= $fullTitle ?></title>
  <meta name="color-scheme" content="dark">
  <meta name="theme-color" content="#073e23">

  <!-- Icons -->
  <link rel="icon" type="image/svg+xml" href="<?= e(base_url('/favicon.svg')) ?>?v=4">
  <link rel="alternate icon" href="<?= e(base_url('/favicon.ico')) ?>?v=4">

  <!-- Preconnects -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="preconnect" href="https://wow.zamimg.com" crossorigin>
  <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
  
<script>
  // viewer.min.js calls WH.debug(...)
  window.WH = window.WH || {};
  if (typeof window.WH.debug !== "function") window.WH.debug = function(){};
  if (typeof window.WH.log !== "function") window.WH.log = function(){};
</script>


<!-- jQuery polyfill for viewer compatibility -->
<script>
if (typeof jQuery !== 'undefined' && !jQuery.isArray) {
    jQuery.isArray = Array.isArray;
}
if (typeof $ !== 'undefined' && !$.isArray) {
    $.isArray = Array.isArray;
}
</script>


  <!-- Fonts -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&family=Cinzel:wght@600;700;800&display=swap" rel="stylesheet">

  <!-- Tailwind (local) -->
  <link rel="preload" href="<?= e($twHref) ?>" as="style">
  <link rel="stylesheet" href="<?= e($twHref) ?>">

  <!-- Theme CSS (after Tailwind) -->
  <link rel="preload" href="<?= e($themeCss) ?>" as="style">
  <link rel="stylesheet" href="<?= e($themeCss) ?>">

  <!-- Prefetch bg (avoid "preload not used" warning) -->
  <link rel="prefetch" href="<?= e($bgImg) ?>">

  <!-- Quick CSS + safety nets (kept exactly as you had) -->
  <style>
    html { -webkit-text-size-adjust:100%; text-size-adjust:100%; }
    .layout-grid { min-height:100dvh; display:grid; grid-template-rows:auto 1fr auto; }
    .layout-grid header.nav-glass.canopy { grid-row:1; }
    .layout-grid footer { grid-row:3; }
    #home.hero-emerald { height:100dvh; }
    @supports not (height:100dvh) { #home.hero-emerald { height:100svh; } }
    header.nav-glass.canopy { position:relative; z-index:50; }
    [data-nav-panel][data-open="false"] { display:none !important; }
    [data-nav-panel][data-open="true"]  { display:block !important; }
    [id^="header-chat-widget-"][id$="-dropdown"]{ top:calc(100% + .5rem) !important; bottom:auto !important; right:0 !important; left:auto !important; }
    .user-menu .user-trigger { cursor:pointer; }
    [id^="header-chat-widget-"] [id$="-toggle"] { cursor:pointer; }
  </style>

  <!-- Quiet console -->
  <script>
  (function(quiet){
    if (!quiet) return;
    var DROP = [
      /Cookie “?__cf_bm”? (has been|will soon be) rejected/i,
      /is foreign and does not have the “?Partitioned/i,
      /Cookie “?__dcfduid”? has been rejected/i,
      /Cookie “?__sdcfduid”? has been rejected/i,
      /Cross-Origin Request Blocked: .*discordapp\.com\/widget-avatars/i,
      /Feature Policy: Skipping unsupported feature name/i,
      /^\[fog-loader]/i,
      /^\[stable-particles]/i,
      /static\.cloudflareinsights\.com\/beacon\.min\.js/i
    ];
    function wrap(name){
      var orig = console[name]; if (!orig) return;
      console[name] = function(){ try{
        var msg = arguments[0] != null ? String(arguments[0]) : '';
        for (var i=0;i<DROP.length;i++) if (DROP[i].test(msg)) return;
      }catch(_){} return orig.apply(this, arguments); };
    }
    ['log','info','warn','error'].forEach(wrap);
    window.addEventListener('error', function(ev){
      try { var msg = String(ev.message||''); if (DROP.some(function(rx){return rx.test(msg)})) { ev.preventDefault(); return false; } } catch(_){}
    }, true);
    window.addEventListener('unhandledrejection', function(ev){
      try { var r = ev.reason; var msg = String((r && (r.message||r))||''); if (DROP.some(function(rx){return rx.test(msg)})) { ev.preventDefault(); return false; } } catch(_){}
    }, true);
  })(<?= $quietConsole ? 'true' : 'false' ?>);
  </script>

  <!-- Minimal, layout-safe scroll smoothers -->

  <!-- 1) Passive-by-default wheel/touch on window/document/html only -->
  <script>
  (function(){
    try {
      var supportsPassive = false;
      var opts = Object.defineProperty({}, 'passive', { get: function(){ supportsPassive = true; } });
      window.addEventListener('x', null, opts); window.removeEventListener('x', null, opts);
      if (!supportsPassive) return;

      function patch(target){
        var _add = target.addEventListener;
        target.addEventListener = function(type, listener, options){
          if ((type === 'wheel' || type === 'touchstart' || type === 'touchmove')
              && (options === undefined || options === false)) {
            options = { passive: true };
          }
          return _add.call(this, type, listener, options);
        };
      }
      patch(window); patch(document); patch(document.documentElement);
    } catch(_) {}
  })();
  </script>
  
  <!-- Name Effects Animation Engine (smooth forward continuous - watches for new elements) -->
  <script>
(function(){
  const effects = {
    emerald:   { cycle: 5500 },
    ember:     { cycle: 4800 },
    aqua:      { cycle: 5800 },
    gold:      { cycle: 4500 },
    ice:       { cycle: 6500 },
    sapphire:  { cycle: 5200 },
    amethyst:  { cycle: 5600 },
    crimson:   { cycle: 5000 },
    "rose-gold":{ cycle: 5300 },
    obsidian:  { cycle: 6700 },
    aurora:    { cycle: 6000 },
    arcane:    { cycle: 5500 },
    voidlight: { cycle: 6200 },
    sunset:    { cycle: 5200 },
    platinum:  { cycle: 5500, multi: "platinum" },
    neonwire:  { cycle: 4700 },
    toxin:     { cycle: 4900 },
    "gm-aegis":{ cycle: 6500, multi: "gm-aegis" },
    rainbow:   { cycle: 4500 }
  };

  const cache = {};
  let startTime = performance.now();
  let lastTick = 0;
  const FPS = 30;
  const frameMs = 1000 / FPS;

  function updateCache() {
    // Refresh cache to include new elements (from AJAX chat loads)
    for (const code in effects) {
      cache[code] = document.querySelectorAll('.nfx-' + code);
    }
  }

  function tick(now){
    if (document.hidden) { requestAnimationFrame(tick); return; }

    if ((now - lastTick) >= frameMs) {
      lastTick = now;
      const elapsed = now - startTime;

      for (const code in cache) {
        const cfg = effects[code];
        // Continuous infinite animation - no reset, no jank
        const pos = ((elapsed / 30) % 1000).toFixed(3);
        const els = cache[code];

        if (cfg.multi === "platinum") {
          // 2-layer background-position
          for (const el of els) el.style.backgroundPosition = pos + '% 50%, ' + pos + '% 50%';
        } else if (cfg.multi === "gm-aegis") {
          // 3-layer background-position
          for (const el of els) el.style.backgroundPosition = pos + '% 50%, ' + pos + '% 50%, ' + pos + '% 50%';
        } else {
          for (const el of els) el.style.backgroundPosition = pos + '% 50%';
        }
      }
    }

    requestAnimationFrame(tick);
  }

  function start(){
    updateCache();
    if (!Object.keys(cache).length) return;
    tick(performance.now());
  }

  // Watch for new elements (AJAX loads, chat updates)
  const observer = new MutationObserver(function(mutations) {
    updateCache();
  });

  function init(){
    updateCache();
    if (Object.keys(cache).length) tick(performance.now());
    observer.observe(document.body, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
</script>


  <!-- 2) rAF scroll aggregator (emits CSS var --scrollY and a custom event) -->
  <script>
  (function(){
    var docEl = document.documentElement, ticking = false, lastY = 0;

    function apply(){
      ticking = false;
      var y = window.scrollY || window.pageYOffset || 0;
      if (y !== lastY){
        lastY = y;
        // cheap to set; ignored by layout unless you use it
        docEl.style.setProperty('--scrollY', String(y));
        // custom event other scripts can subscribe to without spamming main thread
        docEl.dispatchEvent(new CustomEvent('thorium:scroll-raf', { detail: { y:y } }));
      }
    }
    function onScroll(){
      if (!ticking){ ticking = true; requestAnimationFrame(apply); }
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('wheel', onScroll, { passive: true });
    window.addEventListener('touchmove', onScroll, { passive: true });
  })();
  </script>

  <!-- THREE.js + VANTA (fog) -->
  <script defer src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
  <script defer src="https://cdnjs.cloudflare.com/ajax/libs/vanta/0.5.24/vanta.fog.min.js"></script>

  <!-- Optional: force fog/particles on the home hero -->
  <script>window.FOG_FORCE=<?= $forceFogHome ? 'true':'false' ?>; window.PARTICLES_FORCE=<?= $forceParticlesHome ? 'true':'false' ?>;</script>

  <!-- Theme JS -->
  <script defer src="<?= e($navHeroJs) ?>"></script>
  <script defer src="<?= e($themeJs) ?>"></script>
  <script defer src="<?= e($pageBgJs) ?>"></script>
  <script defer src="<?= e($fogLoaderJs) ?>"></script>
  <script defer src="<?= e($partLoaderJs) ?>"></script>
  <script defer src="<?= e($clickCritJs) ?>"></script>

  <!-- Fallback burger if nav-hero.js didn’t run -->
  <script>
  (function(){
    if (window.__navHeroLoaded) return;
    document.addEventListener('DOMContentLoaded', function(){
      var panel = document.querySelector('[data-nav-panel]');
      var toggles = Array.prototype.slice.call(document.querySelectorAll('[data-nav-toggle]'));
      if (!panel || !toggles.length) return;
      function setOpen(next){
        panel.setAttribute('data-open', next ? 'true' : 'false');
        panel.classList.toggle('hidden', !next);
        toggles.forEach(function(b){ b.setAttribute('aria-expanded', next ? 'true' : 'false'); });
        document.documentElement.classList.toggle('nav-open', !!next);
      }
      function toggle(){ setOpen(panel.getAttribute('data-open') !== 'true'); }
      setOpen(false);
      toggles.forEach(function(b){
        b.addEventListener('click', toggle);
        b.addEventListener('keydown', function(e){ if (e.key==='Enter' || e.key===' ') { e.preventDefault(); toggle(); }});
      });
      document.addEventListener('keydown', function(e){ if (e.key==='Escape') setOpen(false); });
    });
  })();
  </script>

  <!-- Layout grid only on non-hero pages -->
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      if (!document.querySelector('#home.hero-emerald')) document.body.classList.add('layout-grid');
    });
  </script>

  <!-- iOS viewport normalize -->
  <script>
    (function () {
      function normalizeViewport() {
        var m = document.querySelector('meta[name="viewport"]'); if (!m) return;
        var c = m.getAttribute('content') || ''; m.setAttribute('content', c);
      }
      window.addEventListener('pageshow', normalizeViewport);
      window.addEventListener('orientationchange', normalizeViewport);
    })();
  </script>

  <!-- Lazy Discord widget loader (opt-in via data-lazy-widget="discord") -->
  <script>
  (function(){
    function boot(el){ if (!el || el.dataset.loaded) return; el.dataset.loaded='1'; el.src = el.dataset.src; }
    function init(){
      var els = document.querySelectorAll('iframe[data-lazy-widget="discord"][data-src]');
      if (!els.length) return;
      els.forEach(function(el){
        var mode = el.getAttribute('data-load') || 'interaction'; // 'visible'|'interaction'|'immediate'
        if (mode === 'immediate') { boot(el); return; }
        if (mode === 'visible') {
          var io = new IntersectionObserver(function(entries){
            entries.forEach(function(ent){ if (ent.isIntersecting){ boot(el); io.unobserve(el); } });
          }, { rootMargin:'200px' });
          io.observe(el);
        } else {
          ['click','mouseenter','focus'].forEach(function(ev){ el.addEventListener(ev, function(){ boot(el); }, { once:true }); });
        }
      });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
  })();
  </script>

  <?php if ($allowCFInsights): ?>
    <!-- Optional Cloudflare Insights -->
    <script defer src="https://static.cloudflareinsights.com/beacon.min.js" data-cf-beacon='{"token": "YOUR_TOKEN"}'></script>
  <?php endif; ?>

  <?php if (function_exists('theme_asset_url')): ?>
    <!-- Click-crit tuning -->
    <script>window.Trolls?.enableClickCrit?.({ min:300, max:5200, critChance:0.18, scale:1.4 });</script>
  <?php endif; ?>
</head>

<body
  class="tui min-h-dvh text-neutral-100 bg-app"
  data-site-bg-src="<?= e($bgImg) ?>"
  data-site-bg-zoom="1.25"
  data-site-bg-follow="0.08"
  data-site-bg-shrink="0.18"
  data-site-bg-blur-factor="0"
  data-site-bg-fade-factor="0"
  data-site-bg-min-opacity="0.2"
  data-fog-enabled="<?= e($fogEnabled) ?>"
  data-fog-preset="<?= e($fogPreset) ?>"
  data-fog-opacity="<?= e($fogOpacity) ?>"
  data-particles-enabled="<?= e($particlesEnabled) ?>"
  data-particles-type="<?= e($particlesType) ?>"
  data-particles-density="<?= e($particlesDensity) ?>"
  data-particles-speed="<?= e($particlesSpeed) ?>"
  data-particles-color="<?= e($particlesColor) ?>"
  data-particles-direction="<?= e($particlesDirection) ?>"
  data-particles-swirl="<?= e($particlesSwirl) ?>"
  data-particles-rotation="<?= e($particlesRotation) ?>"
  data-particles-wind="<?= e($particlesWind) ?>"
  data-particles-gravity="<?= e($particlesGravity) ?>"
>
