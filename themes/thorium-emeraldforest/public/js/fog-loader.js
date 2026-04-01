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
    
    // Read environment settings from body data attributes
    const body = document.body;
    const fogEnabled = body.dataset.fogEnabled === 'true';
    const fogPreset = body.dataset.fogPreset || 'emerald_mist';
    const fogOpacity = parseFloat(body.dataset.fogOpacity || '0.3');
    
    // Check if fog is disabled
    if (!fogEnabled || fogPreset === 'disabled') {
      log("Fog disabled via environment settings");
      return;
    }
    
    // Prevent double init
    if (window.__fogInit) {
      log("Already initialized, skipping.");
      return;
    }
    window.__fogInit = true;
    
    // THREE & VANTA are loaded globally in head.php
    if (!window.THREE || !window.VANTA || !window.VANTA.FOG) {
      err("THREE/VANTA not present. Ensure vendor scripts load before fog-loader.js.");
      return;
    }
    
    log(`Initializing fog with preset: ${fogPreset}, opacity: ${fogOpacity}`);
    
    // Fog preset configurations
    const fogPresets = {
      emerald_mist: {
        speed: 0.4,
        zoom: 0.2,
        blurFactor: 0.15,
        highlightColor: 0xd7ecff,
        midtoneColor: 0xa9cbed,
        lowlightColor: 0x0e1b22,
        baseColor: 0x09141a,
        scale: 1.2,
        scaleMobile: 2.5
      },
      
      ocean_depths: {
        speed: 0.6,
        zoom: 0.3,
        blurFactor: 0.25,
        highlightColor: 0x4fc3f7,  // Light cyan
        midtoneColor: 0x2196f3,    // Blue
        lowlightColor: 0x1565c0,   // Dark blue
        baseColor: 0x0d47a1,       // Very dark blue
        scale: 0.8,
        scaleMobile: 1.5
      },
      
      mystic_forest: {
        speed: 0.3,
        zoom: 0.25,
        blurFactor: 0.2,
        highlightColor: 0xc8e6c9,  // Light green
        midtoneColor: 0x8bc34a,    // Green
        lowlightColor: 0x4a148c,   // Dark purple
        baseColor: 0x1a237e,       // Deep purple
        scale: 1.4,
        scaleMobile: 2.8
      },
      
      arctic_winds: {
        speed: 1.2,
        zoom: 0.4,
        blurFactor: 0.4,
        highlightColor: 0xf5f5f5,  // Almost white
        midtoneColor: 0xe3f2fd,    // Very light blue
        lowlightColor: 0x90caf9,   // Light blue
        baseColor: 0x1e88e5,       // Blue
        scale: 0.6,
        scaleMobile: 1.2
      },
      
      shadow_realm: {
        speed: 0.8,
        zoom: 0.5,
        blurFactor: 0.35,
        highlightColor: 0x7e57c2,  // Purple
        midtoneColor: 0x5e35b1,    // Dark purple
        lowlightColor: 0x311b92,   // Very dark purple
        baseColor: 0x1a0933,       // Almost black purple
        scale: 0.7,
        scaleMobile: 1.4
      },
      
      sunset_glow: {
        speed: 0.5,
        zoom: 0.3,
        blurFactor: 0.3,
        highlightColor: 0xffab40,  // Orange
        midtoneColor: 0xff7043,    // Red-orange
        lowlightColor: 0xd32f2f,   // Red
        baseColor: 0x8d1e1e,       // Dark red
        scale: 1.0,
        scaleMobile: 2.0
      }
    };
    
    // Get the preset configuration
    const presetConfig = fogPresets[fogPreset];
    if (!presetConfig) {
      err(`Unknown fog preset: ${fogPreset}. Using emerald_mist instead.`);
      presetConfig = fogPresets.emerald_mist;
    }
    
    // Ensure a single fog layer exists
    let fog = document.querySelector(".bg-fog-canvas[data-fog-global]");
    if (!fog) {
      fog = document.createElement("div");
      fog.className = "bg-fog-canvas";
      fog.setAttribute("data-fog-global", "");
      Object.assign(fog.style, {
        position: "fixed",
        inset: "0",
        zIndex: "-1",
        pointerEvents: "none"
      });
      document.body.insertBefore(fog, document.body.firstChild);
      log("Created fog container");
    }
    
    // Make sure backdrop stays behind fog
    const backdrop = document.querySelector('.site-backdrop');
    if (backdrop) {
      backdrop.style.zIndex = "-3";
    }
    
    // Set fog opacity
    fog.style.opacity = String(Math.max(0, Math.min(1, fogOpacity)));
    
    const reduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    
    // Create VANTA options from preset
    const opts = {
      el: fog,
      THREE: window.THREE,
      mouseControls: !reduced,
      touchControls: true,
      gyroControls: false,
      minHeight: 200,
      minWidth: 200,
      
      // Apply preset settings
      speed: presetConfig.speed,
      zoom: presetConfig.zoom,
      blurFactor: presetConfig.blurFactor,
      highlightColor: presetConfig.highlightColor,
      midtoneColor: presetConfig.midtoneColor,
      lowlightColor: presetConfig.lowlightColor,
      baseColor: presetConfig.baseColor,
      scale: presetConfig.scale,
      scaleMobile: presetConfig.scaleMobile
    };
    
    // Initialize fog
    try {
      // Destroy existing instance if any
      if (fog._vanta) {
        fog._vanta.destroy();
        fog._vanta = null;
      }
      
      log("Creating VANTA FOG with preset:", fogPreset, opts);
      fog._vanta = window.VANTA.FOG(opts);
      
      if (!fog._vanta) {
        err("VANTA.FOG returned undefined.");
        return;
      }
      
      // Style the canvas
      setTimeout(() => {
        const canvas = fog.querySelector("canvas");
        if (canvas) {
          Object.assign(canvas.style, {
            position: "absolute",
            inset: "0",
            zIndex: "-1",
            pointerEvents: "none"
          });
        }
      }, 100);
      
      log(`Fog initialized successfully with preset: ${fogPreset}`);
      
    } catch (e) {
      err("Fog initialization failed:", e);
    }
    
    // Debug interface for testing different presets
    window.debugFog = {
      currentPreset: fogPreset,
      presets: fogPresets,
      
      // Apply a different preset temporarily
      applyPreset: (presetName) => {
        const preset = fogPresets[presetName];
        if (!preset) {
          err(`Unknown preset: ${presetName}`);
          return;
        }
        
        log(`Applying preset: ${presetName}`);
        
        if (fog._vanta) {
          fog._vanta.destroy();
          fog._vanta = null;
        }
        
        fog.querySelectorAll("canvas").forEach(canvas => canvas.remove());
        
        requestAnimationFrame(() => {
          const newOpts = {
            el: fog,
            THREE: window.THREE,
            mouseControls: !reduced,
            touchControls: true,
            gyroControls: false,
            minHeight: 200,
            minWidth: 200,
            ...preset
          };
          
          fog._vanta = window.VANTA.FOG(newOpts);
          
          setTimeout(() => {
            const canvas = fog.querySelector("canvas");
            if (canvas) {
              Object.assign(canvas.style, {
                position: "absolute",
                inset: "0",
                zIndex: "-1",
                pointerEvents: "none"
              });
            }
          }, 50);
        });
      },
      
      // List available presets
      listPresets: () => {
        console.log("Available fog presets:");
        Object.keys(fogPresets).forEach(name => {
          console.log(`- ${name}`);
        });
      },
      
      // Get current instance
      instance: () => fog._vanta
    };
    
    // Clean up on page unload
    window.addEventListener("beforeunload", () => {
      try { 
        if (fog._vanta) {
          fog._vanta.destroy();
        }
      } catch (e) {
        err("Cleanup error:", e);
      }
    });
    
    log("Fog loader complete!");
    log("Debug commands:");
    log("window.debugFog.listPresets() - List all presets");
    log("window.debugFog.applyPreset('ocean_depths') - Test different preset");
    log(`Current preset: ${fogPreset}`);
  });
})();