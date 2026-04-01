// themes/thorium-emeraldforest/js/nav-hero.js
// Mobile nav + Home hero backdrop (compat: no optional chaining)
document.addEventListener('DOMContentLoaded', function () {
  // =========================
  // Mobile navigation toggle
  // =========================
  var panel   = document.querySelector('[data-nav-panel]');
  var toggles = Array.prototype.slice.call(document.querySelectorAll('[data-nav-toggle]'));
  var mqDesktop = window.matchMedia('(min-width: 768px)');

  function setOpen(next) {
    if (!panel) return;
    if (next) {
      panel.classList.remove('hidden');
      panel.setAttribute('data-open', 'true');
    } else {
      panel.classList.add('hidden');
      panel.setAttribute('data-open', 'false');
    }
    for (var i=0;i<toggles.length;i++){
      toggles[i].setAttribute('aria-expanded', next ? 'true' : 'false');
    }
    document.documentElement.classList.toggle('nav-open', !!next);
  }

  function toggleOpen() {
    var isOpen = panel && panel.getAttribute('data-open') === 'true';
    setOpen(!isOpen);
  }

  if (panel && toggles.length) {
    setOpen(false); // consistent initial state

    toggles.forEach(function(btn){
      btn.addEventListener('click', toggleOpen);
      btn.addEventListener('keydown', function(e){
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggleOpen(); }
      });
    });

    panel.addEventListener('click', function(e){
      if (e.target && e.target.closest && e.target.closest('a')) setOpen(false);
    });

    document.addEventListener('keydown', function(e){
      if (e.key === 'Escape') setOpen(false);
    });

    // auto-close when switching to desktop
    if (mqDesktop && mqDesktop.addEventListener) {
      mqDesktop.addEventListener('change', function (e) { if (e.matches) setOpen(false); });
    }
  }

  // Smooth-on-load for hash routes
  if (location.hash && location.hash.length > 1) {
    var el = document.getElementById(location.hash.slice(1));
    if (el) setTimeout(function(){ el.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 50);
  }

  // Logo: scroll-to-top when already on Home
  (function(){
    var HOME_URL  = document.documentElement.getAttribute('data-home') || '/';
    var HOME_PATH = new URL(HOME_URL, location.href).pathname.replace(/\/+$/,'');
    var herePath  = location.pathname.replace(/\/+$/,'');
    var supportsSmooth = 'scrollBehavior' in document.documentElement.style;

    document.addEventListener('click', function(e){
      var logo = e.target && e.target.closest ? e.target.closest('[data-site-logo]') : null;
      if (!logo) return;
      if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;

      var onHome = (herePath === HOME_PATH) || document.querySelector('#home.hero-emerald');
      if (!onHome) return;

      e.preventDefault();
      if ((window.scrollY || window.pageYOffset) <= 8) return;

      if (supportsSmooth) {
        window.scrollTo({ top: 0, behavior: 'smooth' });
      } else {
        var startY = window.pageYOffset || document.documentElement.scrollTop || 0;
        var duration = 400, start = performance.now();
        var easeOutCubic = function(t){ return 1 - Math.pow(1 - t, 3); };
        (function step(now){
          var p = Math.min(1, (now - start) / duration);
          var y = startY * (1 - easeOutCubic(p));
          window.scrollTo(0, y);
          if (p < 1) requestAnimationFrame(step);
        })(start);
      }
    });
  })();

  // =========================
  // Home page effects (optional)
  // =========================
  var home = document.querySelector('#home.hero-emerald');

  if (home) {
    // Reveal on scroll
    var io = new IntersectionObserver(function(entries){
      entries.forEach(function(e){ if (e.isIntersecting) e.target.classList.add('visible'); });
    }, { threshold: 0.12, rootMargin: '0px 0px -60px 0px' });
    Array.prototype.forEach.call(document.querySelectorAll('.animate-on-scroll'), function(el){ io.observe(el); });

    // Parallax for hero glow
    var glow = document.querySelector('.hero-glow');
    window.addEventListener('scroll', function(){
      var y = window.pageYOffset * 0.25;
      if (glow) glow.style.transform = 'translateY(' + y + 'px)';
    }, { passive: true });

    // First-scroll flag
    var scrolled = false;
    var handleScroll = function(){
      if (!scrolled && window.scrollY > 10) {
        scrolled = true;
        document.body.classList.add('has-scrolled');
        window.removeEventListener('scroll', handleScroll);
      }
    };
    window.addEventListener('scroll', handleScroll, { passive: true });

    // Fixed hero backdrop (zoom + blur + fade on scroll)
    (function setupHeroBackdrop() {
      var heroImg = home.querySelector('.hero-img');
      var src = (home && home.dataset && home.dataset.heroSrc) || (heroImg && (heroImg.currentSrc || heroImg.src));
      if (!src) return;

      var backdrop = document.createElement('div');
      backdrop.className = 'hero-backdrop';
      backdrop.style.backgroundImage = 'url("' + src + '")';
      document.body.insertBefore(backdrop, document.body.firstChild);

      var ua = navigator.userAgent;
      var isChrome = /Chrome/.test(ua) && /Google Inc/.test(navigator.vendor);
      var isSafari = /Safari/.test(ua) && /Apple Computer/.test(navigator.vendor);
      if (!(isChrome || isSafari)) {
        var opaque = document.createElement('div');
        opaque.className = 'hero-opaque';
        backdrop.appendChild(opaque);
      }

      document.body.classList.add('has-hero-backdrop');

      var prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

      var zoomDefault = parseFloat((home && home.dataset && home.dataset.heroZoom) || '1.8');
      var blurFactor  = parseFloat((home && home.dataset && home.dataset.heroBlurFactor) || '0.006');
      var followRate  = parseFloat((home && home.dataset && home.dataset.heroFollow) || '0.12');
      var maxBlurPx   = parseFloat((home && home.dataset && home.dataset.heroMaxBlur) || '20');
      var fadeFactor  = parseFloat((home && home.dataset && home.dataset.heroFadeFactor) || '1.3');
      var minOpacity  = parseFloat((home && home.dataset && home.dataset.heroMinOpacity) || '0.18');

      var imgW = (heroImg && heroImg.naturalWidth)  || 1920;
      var imgH = (heroImg && heroImg.naturalHeight) || 1080;

      var state = { coverWidth: 0, baseWidth: 0, zoom: zoomDefault, ticking: false };

      function computeBase() {
        var vw = Math.max(1, window.innerWidth);
        var vh = Math.max(1, window.innerHeight);
        var coverScale = Math.max(vw / imgW, vh / imgH);
        state.coverWidth = imgW * coverScale;
        state.baseWidth  = state.coverWidth * state.zoom;
      }

      function applyOpaqueOverlay(scrollY) {
        var opaque = backdrop.querySelector('.hero-opaque');
        if (!opaque) return;
        var alpha = Math.min(0.35, Math.max(0, scrollY / 5000));
        opaque.style.opacity = String(alpha);
      }

      function update() {
        var fromTop = window.scrollY || window.pageYOffset || 0;
        var shrink = fromTop / 3;
        var newWidth = Math.max(state.baseWidth - shrink, state.coverWidth);
        var posY = -fromTop * followRate;
        var blur = prefersReduced ? 0 : Math.min(maxBlurPx, fromTop * blurFactor);
        var doc = document.documentElement;
        var maxScroll = Math.max(1, doc.scrollHeight - window.innerHeight);
        var rawOpacity = 1 - ((fromTop / maxScroll) * fadeFactor);
        var opacity = Math.max(minOpacity, Math.min(1, rawOpacity));

        backdrop.style.backgroundSize = Math.round(newWidth) + 'px auto';
        backdrop.style.backgroundPosition = 'center ' + posY.toFixed(1) + 'px';
        backdrop.style.opacity = opacity.toFixed(3);
        backdrop.style.filter = 'blur(' + blur.toFixed(2) + 'px)';
        backdrop.style.webkitFilter = 'blur(' + blur.toFixed(2) + 'px)';

        applyOpaqueOverlay(fromTop);
        state.ticking = false;
      }

      function onScroll() {
        if (!state.ticking) {
          state.ticking = true;
          requestAnimationFrame(update);
        }
      }

      computeBase();
      update();
      window.addEventListener('resize', function(){ computeBase(); update(); }, { passive: true });
      window.addEventListener('scroll', onScroll, { passive: true });
    })();
  }
});
