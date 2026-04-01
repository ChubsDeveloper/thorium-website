// Mobile nav
document.addEventListener('DOMContentLoaded', () => {
  const toggle = document.querySelector('[data-nav-toggle]');
  const panel  = document.querySelector('[data-nav-panel]');
  if (toggle && panel) {
    toggle.addEventListener('click', () => {
      const open = panel.getAttribute('data-open') === 'true';
      panel.setAttribute('data-open', (!open).toString());
      panel.classList.toggle('hidden', open);
    });
  }

  // Home page
  const home = document.querySelector('#home.hero-emerald');

  if (home) {
    // Reveal on scroll
    const io = new IntersectionObserver((entries) => {
      entries.forEach(e => { if (e.isIntersecting) e.target.classList.add('visible'); });
    }, { threshold: 0.12, rootMargin: '0px 0px -60px 0px' });
    document.querySelectorAll('.animate-on-scroll').forEach(el => io.observe(el));

    // Subtle parallax for hero glow
    const glow = document.querySelector('.hero-glow');
    window.addEventListener('scroll', () => {
      const y = window.pageYOffset * 0.25;
      if (glow) glow.style.transform = `translateY(${y}px)`;
    }, { passive: true });

    // Cards deferred animation toggle
    let scrolled = false;
    function handleScroll() {
      if (!scrolled && window.scrollY > 10) {
        scrolled = true;
        document.body.classList.add('has-scrolled');
        window.removeEventListener('scroll', handleScroll);
      }
    }
    window.addEventListener('scroll', handleScroll, { passive: true });

    // =========================
    // Fixed hero backdrop (zoom + blur + fade on scroll)
    // =========================
    (function setupHeroBackdrop() {
      const heroImg = home.querySelector('.hero-img');
      const src = home?.dataset?.heroSrc || heroImg?.currentSrc || heroImg?.src;
      if (!src) return;

      const backdrop = document.createElement('div');
      backdrop.className = 'hero-backdrop';
      backdrop.style.backgroundImage = `url("${src}")`;
      document.body.insertBefore(backdrop, document.body.firstChild);

      // Fallback tint overlay for non-Chrome/Safari
      const ua = navigator.userAgent;
      const isChrome = /Chrome/.test(ua) && /Google Inc/.test(navigator.vendor);
      const isSafari = /Safari/.test(ua) && /Apple Computer/.test(navigator.vendor);
      if (!(isChrome || isSafari)) {
        const opaque = document.createElement('div');
        opaque.className = 'hero-opaque';
        backdrop.appendChild(opaque);
      }

      // Hide inline image only after backdrop exists
      document.body.classList.add('has-hero-backdrop');

      // Motion prefs
      const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

      // Tunables (override via data-attrs on #home)
      const zoomDefault = parseFloat(home?.dataset?.heroZoom || '1.8');        // base zoom
      const blurFactor  = parseFloat(home?.dataset?.heroBlurFactor || '0.006'); // px blur per px scrolled
      const followRate  = parseFloat(home?.dataset?.heroFollow || '0.12');      // vertical drift
      const maxBlurPx   = parseFloat(home?.dataset?.heroMaxBlur || '20');       // blur cap

      // NEW: fade options
      const fadeFactor  = parseFloat(home?.dataset?.heroFadeFactor || '1.3');   // fade speed
      const minOpacity  = parseFloat(home?.dataset?.heroMinOpacity || '0.18');  // floor

      // Use the image's real dimensions for proper "cover" baseline
      const imgW = heroImg?.naturalWidth  || 1920;
      const imgH = heroImg?.naturalHeight || 1080;

      const state = {
        coverWidth: 0,   // min width to cover viewport
        baseWidth:  0,   // coverWidth * zoom
        zoom:       zoomDefault,
        ticking:    false
      };

      function computeBase() {
        const vw = Math.max(1, window.innerWidth);
        const vh = Math.max(1, window.innerHeight);
        const coverScale = Math.max(vw / imgW, vh / imgH);   // "background-size: cover" math
        state.coverWidth = imgW * coverScale;
        state.baseWidth  = state.coverWidth * state.zoom;
      }

      function applyOpaqueOverlay(scrollY) {
        const opaque = backdrop.querySelector('.hero-opaque');
        if (!opaque) return;
        const alpha = Math.min(0.35, Math.max(0, scrollY / 5000));
        opaque.style.opacity = String(alpha);
      }

      function update() {
        const fromTop = window.scrollY || window.pageYOffset || 0;

        // Shrink with scroll but never below "cover" width
        const shrink = fromTop / 3;
        const newWidth = Math.max(state.baseWidth - shrink, state.coverWidth);

        // Gentle vertical follow
        const posY = -fromTop * followRate;

        // Blur / fade
        const blur = prefersReduced ? 0 : Math.min(maxBlurPx, fromTop * blurFactor);
        const doc = document.documentElement;
        const maxScroll = Math.max(1, doc.scrollHeight - window.innerHeight);

        // NEW: fade controlled by data-hero-fade-factor and data-hero-min-opacity
        const rawOpacity = 1 - ((fromTop / maxScroll) * fadeFactor);
        const opacity = Math.max(minOpacity, Math.min(1, rawOpacity));

        // Apply
        backdrop.style.backgroundSize = `${Math.round(newWidth)}px auto`;
        backdrop.style.backgroundPosition = `center ${posY.toFixed(1)}px`;
        backdrop.style.opacity = opacity.toFixed(3);
        backdrop.style.filter = `blur(${blur.toFixed(2)}px)`;
        backdrop.style.webkitFilter = `blur(${blur.toFixed(2)}px)`;

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

      // Recompute on resize/rotate
      window.addEventListener('resize', () => { computeBase(); update(); }, { passive: true });
      window.addEventListener('scroll', onScroll, { passive: true });
    })();
  }
});
