// themes/thorium-emeraldforest/public/js/page-bg.js
// Global parallax background for all pages except home
(() => {
  document.addEventListener('DOMContentLoaded', () => {
    // Skip the home page (it already has its own hero system)
    if (document.querySelector('#home.hero-emerald')) return;

    const body = document.body;

    // Respect reduced motion
    const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // Read options from <body data-*>; provide safe defaults
    const src         = body.dataset.siteBgSrc || '/themes/thorium-emeraldforest/assets/Backgroundv2.png';
    const zoomDefault = parseFloat(body.dataset.siteBgZoom || '1.25');
    const followRate  = prefersReduced ? 0 : parseFloat(body.dataset.siteBgFollow || '0.08');
    const shrinkRate  = parseFloat(body.dataset.siteBgShrink || '0.18');   // px width per px scroll
    const blurFactor  = prefersReduced ? 0 : parseFloat(body.dataset.siteBgBlurFactor || '0');
    const fadeFactor  = parseFloat(body.dataset.siteBgFadeFactor || '0');
    const minOpacity  = parseFloat(body.dataset.siteBgMinOpacity || '0.2');

    if (!src) return;

    // Create a single fixed backdrop if not present
    let backdrop = document.querySelector('.site-backdrop');
    if (!backdrop) {
      backdrop = document.createElement('div');
      backdrop.className = 'site-backdrop';
      body.insertBefore(backdrop, body.firstChild);
    }
    backdrop.style.backgroundImage = `url("${src}")`;

    // Image sizing helpers
    const img = new Image();
    let imgW = 1920, imgH = 1080;
    const state = { coverWidth: 0, baseWidth: 0, ticking: false };

    const computeBase = () => {
      const vw = Math.max(1, window.innerWidth);
      const vh = Math.max(1, window.innerHeight);
      const coverScale = Math.max(vw / imgW, vh / imgH); // background-size: cover
      state.coverWidth = imgW * coverScale;
      state.baseWidth  = state.coverWidth * zoomDefault;
    };

    const update = () => {
      const y = window.scrollY || window.pageYOffset || 0;

      // Gentle zoom-out on scroll, never below "cover"
      const newWidth = Math.max(state.baseWidth - (y * shrinkRate), state.coverWidth);

      // Vertical parallax follow
      const posY = -y * followRate;

      // Optional blur/fade
      const blur = Math.min(30, y * blurFactor);
      const maxScroll = Math.max(1, document.documentElement.scrollHeight - window.innerHeight);
      const rawOpacity = 1 - ((y / maxScroll) * fadeFactor);
      const opacity = Math.max(minOpacity, Math.min(1, rawOpacity));

      // Apply styles
      backdrop.style.backgroundSize = `${Math.round(newWidth)}px auto`;
      backdrop.style.backgroundPosition = `center ${posY.toFixed(1)}px`;
      backdrop.style.filter = `blur(${blur.toFixed(2)}px)`;
      backdrop.style.webkitFilter = `blur(${blur.toFixed(2)}px)`;
      backdrop.style.opacity = opacity.toFixed(3);

      state.ticking = false;
    };

    const onScroll = () => {
      if (!state.ticking) {
        state.ticking = true;
        requestAnimationFrame(update);
      }
    };

    // Init
    computeBase();
    update();
    window.addEventListener('resize', () => { computeBase(); update(); }, { passive: true });
    window.addEventListener('scroll', onScroll, { passive: true });

    img.onload = () => { imgW = img.naturalWidth; imgH = img.naturalHeight; computeBase(); update(); };
    img.src = src;
  });
})();
