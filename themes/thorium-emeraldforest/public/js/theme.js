// theme.js — Thorium Emerald (FF-safe hero: layered image, non-blurred belt, floor lock)

document.addEventListener('DOMContentLoaded', () => {
  /* ---------------------------
     Mobile nav
  --------------------------- */
  const toggle = document.querySelector('[data-nav-toggle]');
  const panel  = document.querySelector('[data-nav-panel]');
  if (toggle && panel) {
    toggle.addEventListener('click', () => {
      const open = panel.getAttribute('data-open') === 'true';
      panel.setAttribute('data-open', (!open).toString());
      panel.classList.toggle('hidden', open);
    });
  }

  /* ---------------------------
     Reveal-on-scroll
  --------------------------- */
  const io = new IntersectionObserver(
    entries => { for (const e of entries) if (e.isIntersecting) e.target.classList.add('visible'); },
    { threshold: 0.12, rootMargin: '0px 0px -60px 0px' }
  );
  document.querySelectorAll('.animate-on-scroll').forEach(el => io.observe(el));

  /* ---------------------------
     Optional hero glow parallax
  --------------------------- */
  const glow = document.querySelector('.hero-glow');
  if (glow) {
    window.addEventListener('scroll', () => {
      glow.style.transform = `translateY(${window.pageYOffset * 0.25}px)`;
    }, { passive: true });
  }

  /* ---------------------------
     Body scrolled flag
  --------------------------- */
  let scrolled = false;
  function onFirstScroll() {
    if (!scrolled && window.scrollY > 10) {
      scrolled = true;
      document.body.classList.add('has-scrolled');
      window.removeEventListener('scroll', onFirstScroll);
    }
  }
  window.addEventListener('scroll', onFirstScroll, { passive: true });

  /* ---------------------------
     Fixed Hero Backdrop (layered to avoid FF seam tint)
  --------------------------- */
  (function setupHeroBackdrop() {
    const home = document.querySelector('#home.hero-emerald');
    if (!home) return;

    const heroImg = home.querySelector('.hero-img');
    const src = home?.dataset?.heroSrc || heroImg?.currentSrc || heroImg?.src || '';
    if (!src) return;

    // Root fixed backdrop (kept fully opaque)
    const root = document.createElement('div');
    root.className = 'hero-backdrop';
    root.style.position = 'fixed';
    root.style.inset = '0';
    root.style.zIndex = '0';
    root.style.backgroundColor = 'var(--bg-forest)'; // solid floor color behind everything
    root.style.willChange = 'transform';
    root.style.transform = 'translateZ(0.001px)'; // avoids FF hairlines
    document.body.insertBefore(root, document.body.firstChild);

    // Image layer (gets zoom/blur/follow)
    const imgLayer = document.createElement('div');
    imgLayer.className = 'hb-img';
    Object.assign(imgLayer.style, {
      position: 'absolute',
      inset: '0',
      backgroundImage: `url("${src}")`,
      backgroundRepeat: 'no-repeat',
      backgroundSize: 'cover',
      backgroundPosition: 'center center',
      willChange: 'background-size, background-position, transform, filter',
      filter: 'blur(0px)',
      WebkitFilter: 'blur(0px)',
      transform: 'translateZ(0.001px)'
    });
    root.appendChild(imgLayer);

    // Neutral scrim (darken slightly, multiplies; fades to 0 near bottom)
    const scrim = document.createElement('div');
    scrim.className = 'hb-scrim';
    Object.assign(scrim.style, {
      position: 'absolute',
      inset: '0',
      background: 'rgba(0,0,0,.5)',
      mixBlendMode: 'multiply',
      opacity: 'var(--scrim-alpha, 0)',
      pointerEvents: 'none',
      zIndex: '2'
    });
    root.appendChild(scrim);

    // Non-blurred bottom belt that fades to exact page color
    // (because it sits above the blurred image, the seam can’t darken)
    const belt = document.createElement('div');
    belt.className = 'hb-belt';
    Object.assign(belt.style, {
      position: 'absolute',
      left: '0',
      right: '0',
      bottom: '0',
      height: '220px', // dynamic below
      background: 'linear-gradient(180deg, rgba(0,0,0,0) 0%, var(--bg-forest) 85%)',
      pointerEvents: 'none',
      zIndex: '3'
    });
    root.appendChild(belt);

    // Floor lock (last-pixel guarantee)
    const floor = document.createElement('div');
    floor.className = 'hero-floor-lock';
    Object.assign(floor.style, {
      position: 'fixed',
      left: '0', right: '0', bottom: '0',
      height: '12px',                    // increase to 16px if you still see a line
      background: 'var(--bg-forest)',
      pointerEvents: 'none',
      zIndex: '4',
      opacity: '1',
      transition: 'opacity .18s linear'
    });
    document.body.insertBefore(floor, root.nextSibling);

    // Hide inline <img> once backdrop exists
    document.body.classList.add('has-hero-backdrop');

    // Motion prefs
    const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // Tunables (from #home data-*)
    const zoomDefault = parseFloat(home?.dataset?.heroZoom || '1.8');
    const blurFactor  = parseFloat(home?.dataset?.heroBlurFactor || '0.006');
    const followRate  = parseFloat(home?.dataset?.heroFollow || '0.12');
    const maxBlurPx   = parseFloat(home?.dataset?.heroMaxBlur || '20');

    // Natural sizes for background-size: cover
    let imgW = heroImg?.naturalWidth  || 1920;
    let imgH = heroImg?.naturalHeight || 1080;

    // Dynamic geometry
    let beltH = 220;

    const state = {
      coverWidth: 0,
      baseWidth:  0,
      zoom:       zoomDefault,
      ticking:    false,
    };

    function computeBase() {
      const vw = Math.max(1, window.innerWidth);
      const vh = Math.max(1, window.innerHeight);

      // Belt height scales with viewport; ensures seam area is fully “non-blurred”
      beltH = Math.max(180, Math.min(320, Math.round(vh * 0.3)));
      belt.style.height = `${beltH}px`;

      const coverScale = Math.max(vw / imgW, vh / imgH); // cover math
      state.coverWidth = imgW * coverScale;
      state.baseWidth  = state.coverWidth * state.zoom;
    }

    function update() {
      const fromTop = window.scrollY || window.pageYOffset || 0;

      // Zoom-out with scroll but never below cover width
      const shrink = fromTop / 3;
      const newWidth = Math.max(state.baseWidth - shrink, state.coverWidth);

      // Gentle vertical follow
      const posY = -fromTop * followRate;

      // Blur (cap slightly lower to reduce FF compositing artifacts)
      const blurCap = Math.min(maxBlurPx, 16);
      const blur = prefersReduced ? 0 : Math.min(blurCap, fromTop * blurFactor);

      // Apply to IMAGE layer (not the belt)
      imgLayer.style.backgroundSize = `${Math.round(newWidth)}px auto`;
      imgLayer.style.backgroundPosition = `center ${posY.toFixed(1)}px`;
      const blurStr = `blur(${blur.toFixed(2)}px)`;
      imgLayer.style.filter = blurStr;
      imgLayer.style.WebkitFilter = blurStr;

      // Scrim strength: subtle, and falls off hard near the hero bottom
      const heroH = home.offsetHeight || window.innerHeight;
      const t = Math.min(1, Math.max(0, fromTop / Math.max(1, heroH))); // 0 top → 1 bottom
      const scrimBase = Math.min(0.20, fromTop / 3400);
      const scrimA = scrimBase * (1 - t) * (1 - t); // quadratic falloff
      root.style.setProperty('--scrim-alpha', scrimA.toFixed(3));
      scrim.style.opacity = `var(--scrim-alpha, ${scrimA.toFixed(3)})`;

      // Floor visible only while hero is on screen
      floor.style.opacity = (fromTop < heroH) ? '1' : '0';

      state.ticking = false;
    }

    function onScroll() {
      if (!state.ticking) {
        state.ticking = true;
        requestAnimationFrame(update);
      }
    }

    function initAfterImageReady() {
      if (heroImg && heroImg.complete && heroImg.naturalWidth) {
        imgW = heroImg.naturalWidth;
        imgH = heroImg.naturalHeight;
      }
      computeBase();
      update();
      window.addEventListener('resize', () => { computeBase(); update(); }, { passive: true });
      window.addEventListener('scroll', onScroll, { passive: true });
    }

    if (heroImg && !heroImg.complete) {
      if ('decode' in heroImg) {
        heroImg.decode().catch(() => {}).finally(initAfterImageReady);
      } else {
        heroImg.addEventListener('load', initAfterImageReady, { once: true });
        heroImg.addEventListener('error', initAfterImageReady, { once: true });
      }
    } else {
      initAfterImageReady();
    }
  })();
});
