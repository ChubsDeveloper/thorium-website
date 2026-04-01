// themes/thorium-emeraldforest/public/js/page-bg.js
// Background with effects but bulletproof coverage
(() => {
  document.addEventListener('DOMContentLoaded', () => {
    // Skip the home page
    if (document.querySelector('#home.hero-emerald')) return;
    
    const body = document.body;
    
    // Respect reduced motion
    const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    
    // Read options but with more conservative defaults
    const src         = body.dataset.siteBgSrc || '/themes/thorium-emeraldforest/assets/Backgroundv2.png';
    const zoomDefault = parseFloat(body.dataset.siteBgZoom || '1.25');
    const followRate  = prefersReduced ? 0 : parseFloat(body.dataset.siteBgFollow || '0.03'); // Reduced from 0.08
    const shrinkRate  = parseFloat(body.dataset.siteBgShrink || '0.1');   // Reduced from 0.18
    const blurFactor  = prefersReduced ? 0 : parseFloat(body.dataset.siteBgBlurFactor || '0');
    const fadeFactor  = parseFloat(body.dataset.siteBgFadeFactor || '0');
    const minOpacity  = parseFloat(body.dataset.siteBgMinOpacity || '0.2');
    
    if (!src) return;
    
    // Create backdrop element
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
    const state = { coverWidth: 0, coverHeight: 0, baseWidth: 0, baseHeight: 0, ticking: false };
    
    const computeBase = () => {
      const vw = Math.max(1, window.innerWidth);
      const vh = Math.max(1, window.innerHeight);
      const coverScale = Math.max(vw / imgW, vh / imgH);
      
      state.coverWidth = imgW * coverScale;
      state.coverHeight = imgH * coverScale;
      state.baseWidth = state.coverWidth * zoomDefault;
      state.baseHeight = state.coverHeight * zoomDefault;
    };
    
    const update = () => {
      const y = window.scrollY || window.pageYOffset || 0;
      const vw = Math.max(1, window.innerWidth);
      const vh = Math.max(1, window.innerHeight);
      
      // Calculate maximum scroll to determine page length
      const maxScroll = Math.max(1, document.documentElement.scrollHeight - window.innerHeight);
      const isLongPage = maxScroll > vh * 1.5;
      
      // Conservative parallax - much reduced on long pages
      const actualFollowRate = isLongPage ? followRate * 0.3 : followRate;
      const posY = -y * actualFollowRate;
      
      // Calculate maximum possible movement for sizing
      const maxMovement = maxScroll * actualFollowRate;
      
      // Generous sizing with big buffers
      const shrinkAmount = y * shrinkRate;
      const newWidth = Math.max(state.baseWidth - shrinkAmount, state.coverWidth * 1.2); // 20% minimum buffer
      const newHeight = Math.max(state.baseHeight - (shrinkAmount * imgH / imgW), state.coverHeight * 1.2);
      
      // Add extra height to account for movement + generous buffer
      const finalWidth = Math.max(newWidth, state.coverWidth * 1.3);
      const finalHeight = Math.max(newHeight + maxMovement + vh * 0.3, state.coverHeight * 1.5); // 50% extra height
      
      // Optional blur/fade
      const blur = Math.min(30, y * blurFactor);
      const rawOpacity = 1 - ((y / maxScroll) * fadeFactor);
      const opacity = Math.max(minOpacity, Math.min(1, rawOpacity));
      
      // Apply styles with safeguards
      backdrop.style.backgroundSize = `${Math.round(finalWidth)}px ${Math.round(finalHeight)}px`;
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
    
    // Initialize
    computeBase();
    update();
    
    window.addEventListener('resize', () => { 
      computeBase(); 
      update(); 
    }, { passive: true });
    
    window.addEventListener('scroll', onScroll, { passive: true });
    
    img.onload = () => { 
      imgW = img.naturalWidth; 
      imgH = img.naturalHeight; 
      computeBase(); 
      update(); 
    };
    img.src = src;
  });
})();