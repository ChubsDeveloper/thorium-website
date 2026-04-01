// themes/thorium-emeraldforest/public/js/particle-loader.js
// Simplified Stable Particle System
(() => {
  const ready = (fn) =>
    document.readyState !== "loading"
      ? fn()
      : document.addEventListener("DOMContentLoaded", fn);
  const log = (...a) => console.log("[stable-particles]", ...a);
  const err = (...a) => console.error("[stable-particles]", ...a);
  
  ready(() => {
    // Skip the Home page (hero handles visuals there)
    if (document.querySelector("#home.hero-emerald")) {
      log("Home detected, skipping particles.");
      return;
    }
    
    // Read environment settings from body data attributes
    const body = document.body;
    const particlesEnabled = body.dataset.particlesEnabled === 'true';
    const particlesType = body.dataset.particlesType || 'leaves';
    const particlesDensity = body.dataset.particlesDensity || 'medium';
    const particlesSpeed = body.dataset.particlesSpeed || 'slow';
    const particlesColor = body.dataset.particlesColor || 'auto';
    
    // Enhanced particle settings
    const particlesDirection = body.dataset.particlesDirection || 'down';
    const particlesSwirl = body.dataset.particlesSwirl === 'true';
    const particlesRotation = body.dataset.particlesRotation === 'true';
    const particlesWind = parseFloat(body.dataset.particlesWind) || 0.8;
    const particlesGravity = parseFloat(body.dataset.particlesGravity) || 1.0;
    
    // Debug: Log what we found
    log('Particle settings:', {
      enabled: particlesEnabled,
      type: particlesType,
      density: particlesDensity,
      speed: particlesSpeed,
      direction: particlesDirection,
      wind: particlesWind,
      gravity: particlesGravity
    });
    
    // Check if particles are disabled
    if (!particlesEnabled || particlesType === 'disabled') {
      log("Particles disabled via environment settings");
      return;
    }
    
    // Prevent double init
    if (window.__particlesInit) {
      log("Particles already initialized, skipping.");
      return;
    }
    window.__particlesInit = true;
    
    // Check if THREE.js is available
    if (!window.THREE) {
      err("THREE.js not available. Particles require THREE.js.");
      return;
    }
    
    log(`Initializing stable particles: ${particlesType}, density: ${particlesDensity}, speed: ${particlesSpeed}`);
    
    // Simple density configurations
    const densityConfig = {
      low: 12,
      medium: 25,
      high: 40,
      very_high: 60
    };
    
    // Simple speed configurations
    const speedConfig = {
      very_slow: 0.01,
      slow: 0.02,
      medium: 0.04,
      fast: 0.08,
      very_fast: 0.15
    };
    
    // Direction configurations
    const directionConfig = {
      down: { x: 0, y: -1, z: 0 },
      up: { x: 0, y: 1, z: 0 },
      left: { x: -1, y: -0.3, z: 0 },
      right: { x: 1, y: -0.3, z: 0 },
      diagonal_left: { x: -0.7, y: -0.7, z: 0 },
      diagonal_right: { x: 0.7, y: -0.7, z: 0 },
      swirl: { x: 0, y: -0.8, z: 0 }
    };
    
    // Color palettes
    const colorConfig = {
      auto: null,
      green: [0x2e7d32, 0x388e3c, 0x4caf50, 0x66bb6a, 0x81c784],
      blue: [0x1565c0, 0x1976d2, 0x2196f3, 0x42a5f5, 0x64b5f6],
      gold: [0xf57c00, 0xfb8c00, 0xffc107, 0xffd54f, 0xffe082],
      purple: [0x4a148c, 0x6a1b9a, 0x8e24aa, 0xab47bc, 0xba68c8],
      white: [0xffffff, 0xf5f5f5, 0xe8eaf6, 0xeceff1, 0xf8f9fa],
      rainbow: [0xff1744, 0xff9800, 0xffeb3b, 0x4caf50, 0x2196f3, 0x9c27b0]
    };
    
    // Simplified particle type configurations
    const particleTypeConfig = {
      leaves: {
        sizeRange: [0.08, 0.15],
        colors: [0x8bc34a, 0xffc107, 0xff8f00, 0xd32f2f, 0x795548, 0x689f38],
        opacity: [0.7, 0.9]
      },
      petals: {
        sizeRange: [0.06, 0.12],
        colors: [0xe91e63, 0xf48fb1, 0xfce4ec, 0xf8bbd9, 0xffcdd2, 0xf06292],
        opacity: [0.6, 0.8]
      },
      fireflies: {
        sizeRange: [0.04, 0.08],
        colors: [0xffeb3b, 0xffc107, 0x4caf50, 0x8bc34a, 0xcddc39],
        opacity: [0.8, 1.0],
        glow: true
      },
      sparkles: {
        sizeRange: [0.03, 0.07],
        colors: [0x64b5f6, 0x42a5f5, 0x90caf9, 0xe3f2fd, 0xffffff, 0xbbdefb],
        opacity: [0.7, 1.0],
        glow: true
      },
      snow: {
        sizeRange: [0.03, 0.08],
        colors: [0xffffff, 0xf5f5f5, 0xe8eaf6, 0xeceff1],
        opacity: [0.6, 0.9]
      }
    };
    
    // Get configurations
    const particleCount = densityConfig[particlesDensity] || 25;
    const baseSpeed = speedConfig[particlesSpeed] || 0.02;
    const direction = directionConfig[particlesDirection] || directionConfig.down;
    const typeConfig = particleTypeConfig[particlesType] || particleTypeConfig.leaves;
    
    // Determine colors
    let particleColors;
    if (particlesColor === 'auto') {
      particleColors = typeConfig.colors;
    } else {
      particleColors = colorConfig[particlesColor] || typeConfig.colors;
    }
    
    log(`Using ${particleCount} particles with ${particleColors.length} color variations`);
    
    // Create particle container
    let particleContainer = document.querySelector(".particle-canvas[data-particles-global]");
    if (!particleContainer) {
      particleContainer = document.createElement("div");
      particleContainer.className = "particle-canvas";
      particleContainer.setAttribute("data-particles-global", "");
      Object.assign(particleContainer.style, {
        position: "fixed",
        inset: "0",
        zIndex: "0",
        pointerEvents: "none",
        overflow: "hidden"
      });
      document.body.appendChild(particleContainer);
      log("Created particle container");
    }
    
    // Initialize Three.js scene
    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(75, window.innerWidth / window.innerHeight, 0.1, 1000);
    const renderer = new THREE.WebGLRenderer({ alpha: true, antialias: true });
    
    renderer.setSize(window.innerWidth, window.innerHeight);
    renderer.setClearColor(0x000000, 0);
    particleContainer.appendChild(renderer.domElement);
    
    camera.position.z = 5;
    
    // Create beautiful particle textures
    function createParticleTexture(type, variation = 0) {
      const canvas = document.createElement('canvas');
      const size = 64;
      canvas.width = canvas.height = size;
      const ctx = canvas.getContext('2d');
      
      ctx.clearRect(0, 0, size, size);
      
      switch (type) {
        case 'leaves':
          // Simple leaf shape
          const leafColor = `hsl(${80 + (variation * 60) % 40}, 70%, 45%)`;
          ctx.fillStyle = leafColor;
          
          ctx.beginPath();
          ctx.moveTo(size/2, size * 0.1);
          ctx.quadraticCurveTo(size * 0.8, size * 0.3, size * 0.7, size * 0.6);
          ctx.quadraticCurveTo(size/2, size * 0.9, size * 0.3, size * 0.6);
          ctx.quadraticCurveTo(size * 0.2, size * 0.3, size/2, size * 0.1);
          ctx.fill();
          
          // Simple vein
          ctx.strokeStyle = `hsl(${80 + (variation * 60) % 40}, 60%, 30%)`;
          ctx.lineWidth = 1;
          ctx.beginPath();
          ctx.moveTo(size/2, size * 0.15);
          ctx.lineTo(size/2, size * 0.75);
          ctx.stroke();
          break;
          
        case 'petals':
          // Simple petal
          const petalHue = 320 + (variation * 80) % 60;
          ctx.fillStyle = `hsl(${petalHue}, 70%, 70%)`;
          ctx.beginPath();
          ctx.moveTo(size/2, size * 0.1);
          ctx.quadraticCurveTo(size * 0.8, size * 0.4, size/2, size * 0.9);
          ctx.quadraticCurveTo(size * 0.2, size * 0.4, size/2, size * 0.1);
          ctx.fill();
          break;
          
        case 'fireflies':
          // Glowing circle
          const glowGradient = ctx.createRadialGradient(size/2, size/2, 0, size/2, size/2, size/3);
          glowGradient.addColorStop(0, '#ffeb3b');
          glowGradient.addColorStop(0.7, '#ffc107');
          glowGradient.addColorStop(1, 'rgba(255, 193, 7, 0)');
          ctx.fillStyle = glowGradient;
          ctx.fillRect(0, 0, size, size);
          break;
          
        case 'sparkles':
          // Simple star
          ctx.fillStyle = '#64b5f6';
          ctx.translate(size/2, size/2);
          for (let i = 0; i < 8; i++) {
            ctx.rotate(Math.PI / 4);
            ctx.beginPath();
            ctx.moveTo(0, 0);
            ctx.lineTo(0, -size/4);
            ctx.lineTo(size/12, -size/8);
            ctx.lineTo(0, 0);
            ctx.lineTo(-size/12, -size/8);
            ctx.fill();
          }
          break;
          
        case 'snow':
          // Simple snowflake
          ctx.strokeStyle = '#ffffff';
          ctx.lineWidth = 1;
          ctx.translate(size/2, size/2);
          for (let i = 0; i < 6; i++) {
            ctx.rotate(Math.PI / 3);
            ctx.beginPath();
            ctx.moveTo(0, -size/4);
            ctx.lineTo(0, size/4);
            ctx.moveTo(-size/8, -size/8);
            ctx.lineTo(size/8, size/8);
            ctx.moveTo(size/8, -size/8);
            ctx.lineTo(-size/8, size/8);
            ctx.stroke();
          }
          break;
          
        default:
          // Simple circle
          ctx.fillStyle = '#4caf50';
          ctx.beginPath();
          ctx.arc(size/2, size/2, size/4, 0, Math.PI * 2);
          ctx.fill();
      }
      
      const texture = new THREE.CanvasTexture(canvas);
      texture.needsUpdate = true;
      return texture;
    }
    
    // Create stable particle system
    function createStableParticleSystem() {
      const geometry = new THREE.BufferGeometry();
      const positions = [];
      const colors = [];
      const sizes = [];
      const velocities = [];
      const rotations = [];
      const swirlOffsets = [];
      
      for (let i = 0; i < particleCount; i++) {
        // Spawn positions
        const spawnX = (Math.random() - 0.5) * 20;
        const spawnY = Math.random() * 3 + 8;
        const spawnZ = (Math.random() - 0.5) * 5;
        
        positions.push(spawnX, spawnY, spawnZ);
        
        // Colors
        const color = new THREE.Color(particleColors[Math.floor(Math.random() * particleColors.length)]);
        colors.push(color.r, color.g, color.b);
        
        // Sizes
        const sizeMin = typeConfig.sizeRange[0];
        const sizeMax = typeConfig.sizeRange[1];
        const size = sizeMin + Math.random() * (sizeMax - sizeMin);
        sizes.push(size);
        
        // STABLE VELOCITIES - no complex physics
        const windEffect = (Math.random() - 0.5) * particlesWind * 0.005; // Much smaller wind
        const gravityEffect = baseSpeed * particlesGravity;
        
        velocities.push(
          direction.x * gravityEffect + windEffect, // x
          direction.y * gravityEffect,             // y (main movement)
          (Math.random() - 0.5) * 0.001           // z (minimal drift)
        );
        
        // Simple rotation
        rotations.push(Math.random() * Math.PI * 2);
        
        // Swirl offset
        swirlOffsets.push(Math.random() * Math.PI * 2);
      }
      
      geometry.setAttribute('position', new THREE.Float32BufferAttribute(positions, 3));
      geometry.setAttribute('color', new THREE.Float32BufferAttribute(colors, 3));
      geometry.setAttribute('size', new THREE.Float32BufferAttribute(sizes, 1));
      
      // Store data
      geometry.userData = {
        velocities,
        rotations,
        swirlOffsets
      };
      
      // Create material
      const texture = createParticleTexture(particlesType, Math.random());
      
      let material;
      if (typeConfig.glow) {
        material = new THREE.PointsMaterial({
          size: 0.15,
          map: texture,
          vertexColors: true,
          blending: THREE.AdditiveBlending,
          transparent: true,
          opacity: 0.8,
          sizeAttenuation: true
        });
      } else {
        material = new THREE.PointsMaterial({
          size: 0.12,
          map: texture,
          vertexColors: true,
          transparent: true,
          opacity: 0.8,
          sizeAttenuation: true
        });
      }
      
      return new THREE.Points(geometry, material);
    }
    
    // Initialize particle system
    let particleSystem = createStableParticleSystem();
    scene.add(particleSystem);
    
    // STABLE animation loop
    let animationId;
    const clock = new THREE.Clock();
    let time = 0;
    
    function animateStably() {
      animationId = requestAnimationFrame(animateStably);
      
      const delta = clock.getDelta();
      time += delta;
      
      const positions = particleSystem.geometry.attributes.position.array;
      const userData = particleSystem.geometry.userData;
      const { velocities, rotations, swirlOffsets } = userData;
      
      for (let i = 0; i < particleCount; i++) {
        const i3 = i * 3;
        
        // VERY MINIMAL swirl effect
        if (particlesSwirl || particlesDirection === 'swirl') {
          const swirlAngle = time * 0.2 + swirlOffsets[i];
          velocities[i3] += Math.cos(swirlAngle) * 0.00005; // Tiny effect
          velocities[i3 + 2] += Math.sin(swirlAngle) * 0.00005;
        }
        
        // VERY MINIMAL natural sway (only for visual interest)
        if (particlesType === 'leaves' || particlesType === 'petals') {
          const sway = Math.sin(time * 1.5 + swirlOffsets[i]) * 0.00002; // Tiny sway
          velocities[i3] += sway;
        }
        
        // Update positions with CONSISTENT velocities
        positions[i3] += velocities[i3];
        positions[i3 + 1] += velocities[i3 + 1];
        positions[i3 + 2] += velocities[i3 + 2];
        
        // Simple rotation
        if (particlesRotation) {
          rotations[i] += 0.01;
        }
        
        // Reset when particle goes off screen
        const resetCondition = 
          (direction.y < 0 && positions[i3 + 1] < -6) ||
          (direction.y > 0 && positions[i3 + 1] > 16) ||
          (Math.abs(positions[i3]) > 15) ||
          (Math.abs(positions[i3 + 2]) > 8);
          
        if (resetCondition) {
          // Reset to spawn area
          positions[i3] = (Math.random() - 0.5) * 20;
          positions[i3 + 2] = (Math.random() - 0.5) * 5;
          
          if (direction.y < 0) {
            positions[i3 + 1] = Math.random() * 3 + 8;
          } else {
            positions[i3 + 1] = Math.random() * 3 - 8;
          }
          
          // Reset velocity to original
          const windEffect = (Math.random() - 0.5) * particlesWind * 0.005;
          const gravityEffect = baseSpeed * particlesGravity;
          
          velocities[i3] = direction.x * gravityEffect + windEffect;
          velocities[i3 + 1] = direction.y * gravityEffect;
          velocities[i3 + 2] = (Math.random() - 0.5) * 0.001;
        }
      }
      
      particleSystem.geometry.attributes.position.needsUpdate = true;
      
      // Minimal global rotation
      if (particlesType === 'sparkles') {
        particleSystem.rotation.z += 0.001;
      }
      
      renderer.render(scene, camera);
    }
    
    // Handle window resize
    function onWindowResize() {
      camera.aspect = window.innerWidth / window.innerHeight;
      camera.updateProjectionMatrix();
      renderer.setSize(window.innerWidth, window.innerHeight);
    }
    
    window.addEventListener('resize', onWindowResize, false);
    
    // Start animation
    animateStably();
    
    // Debug interface
    window.debugParticles = {
      currentType: particlesType,
      particleCount: particleCount,
      
      changeType: (newType) => {
        log(`Changing to stable ${newType} particles`);
        
        scene.remove(particleSystem);
        if (particleSystem.geometry) particleSystem.geometry.dispose();
        if (particleSystem.material) {
          if (particleSystem.material.map) particleSystem.material.map.dispose();
          particleSystem.material.dispose();
        }
        
        body.dataset.particlesType = newType;
        particleSystem = createStableParticleSystem();
        scene.add(particleSystem);
        
        window.debugParticles.currentType = newType;
      },
      
      toggle: () => {
        particleContainer.style.display = 
          particleContainer.style.display === 'none' ? 'block' : 'none';
      },
      
      getInfo: () => ({
        type: window.debugParticles.currentType,
        count: particleCount,
        stable: true,
        physics: 'simplified'
      })
    };
    
    // Cleanup
    window.addEventListener("beforeunload", () => {
      try {
        if (animationId) cancelAnimationFrame(animationId);
        if (particleSystem) {
          scene.remove(particleSystem);
          if (particleSystem.geometry) particleSystem.geometry.dispose();
          if (particleSystem.material) {
            if (particleSystem.material.map) particleSystem.material.map.dispose();
            particleSystem.material.dispose();
          }
        }
        if (renderer) renderer.dispose();
        log("Stable particles cleaned up");
      } catch (e) {
        err("Cleanup error:", e);
      }
    });
    
    log("Stable particle system initialized!");
    log("Particles should now fall consistently without erratic movement");
  });
})();