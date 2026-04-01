// themes/thorium-emeraldforest/public/js/fog-loader.js
(() => {
  const ready = (fn) =>
    document.readyState !== "loading"
      ? fn()
      : document.addEventListener("DOMContentLoaded", fn);

  const log = (...a) => console.log("[fog-loader]", ...a);
  const err = (...a) => console.error("[fog-loader]", ...a);

  ready(() => {
    // Skip the Home page (hero handles visuals there)
    if (document.querySelector("#home.hero-emerald")) {
      log("Home detected, skipping fog.");
      return;
    }

    // Prevent double init
    if (window.__fogInit) return;
    window.__fogInit = true;

    // THREE & VANTA are loaded globally in head.php
    if (!window.THREE || !window.VANTA || !window.VANTA.FOG) {
      err("THREE/VANTA not present. Ensure vendor scripts load before fog-loader.js.");
      return;
    }

    // Ensure a single fog layer exists (fixed, behind content)
    let fog = document.querySelector(".bg-fog-canvas[data-fog-global]");
    if (!fog) {
      fog = document.createElement("div");
      fog.className = "bg-fog-canvas";
      fog.setAttribute("data-fog-global", "");
      // harden baseline in case CSS isn't loaded yet
      Object.assign(fog.style, {
        position: "fixed",
        inset: "0",
        zIndex: "-2",
        pointerEvents: "none"
      });
      document.body.insertBefore(fog, document.body.firstChild);
    }

    // Subtle overall strength (can override per-page with <body data-fog-opacity="0.22">)
    const body = document.body;
    const fogOpacity = parseFloat(body.dataset.fogOpacity || "0.24");
    fog.style.opacity = String(Math.max(0, Math.min(1, fogOpacity)));

    const reduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    // Tuned to the calmer look from before
// Tuned to feel looser/softer
const opts = {
  el: fog,
  THREE: window.THREE,
  mouseControls: !reduced,
  touchControls: true,
  gyroControls: false,
  minHeight: 200,
  minWidth: 200,

  // Motion & density
  speed: 0.24,       // a hair slower
  zoom: 0.16,        // < 1.0 = larger features (looser)
  blurFactor: 0.10,  // a bit more layering softness

  // Cool blue palette
  highlightColor: 0xd7ecff,
  midtoneColor:   0xa9cbed,
  lowlightColor:  0x0e1b22,
  baseColor:      0x09141a,

  // Render scale (bigger number = softer, less “tight”)
  scale: 1.2,        // desktop
  scaleMobile: 2.8   // mobile
};


    // Re-init safe
    try {
      fog._vanta && fog._vanta.destroy?.();
      fog._vanta = window.VANTA.FOG(opts);
      if (!fog._vanta) err("VANTA.FOG returned undefined.");

      // Keep canvas behind everything (some UIs create new stacking contexts)
      // Re-apply fixed/negative z-index after Vanta injects its canvas
      const canvas = fog.querySelector("canvas");
      if (canvas) {
        Object.assign(canvas.style, {
          position: "absolute",
          inset: "0",
          zIndex: "-2",
          pointerEvents: "none"
        });
      }
    } catch (e) {
      err("Init failed:", e);
    }

    // Clean up on hot reload/nav swaps
    window.addEventListener("beforeunload", () => {
      try { fog._vanta && fog._vanta.destroy?.(); } catch {}
    });
  });
})();
