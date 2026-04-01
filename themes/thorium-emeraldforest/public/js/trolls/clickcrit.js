// Background Click → Floating WoW-style damage number
// - Ignores interactive elements (buttons, links, inputs, etc.)
// - Random normal/crit values with subtle pop + drift animation
;(() => {
  const Trolls = (window.Trolls = window.Trolls || {})

  const cfg = {
    min: 230,          // base min damage
    max: 4200,         // base max damage
    critChance: 0.22,  // 22% crits
    scale: 1,          // global scale
    exclude: 'a,button,[role="button"],input,textarea,select,label,summary,details,[contenteditable="true"],video,audio,iframe,.no-crit',
    leftButtonOnly: true
  }

  // allow runtime tweaking: Trolls.enableClickCrit({ max: 8000, critChance: .35 })
  Trolls.enableClickCrit = (opts = {}) => Object.assign(cfg, opts)

  const isInteractive = (el) => !!el.closest(cfg.exclude)
  const randInt = (a, b) => (a + Math.floor(Math.random() * (b - a + 1)))

  function spawnNumber(x, y) {
    const isCrit = Math.random() < cfg.critChance
    const base = randInt(cfg.min, cfg.max)
    const dmg = isCrit ? Math.round(base * (1.8 + Math.random() * 0.6)) : base

    const el = document.createElement('div')
    el.textContent = dmg.toLocaleString()
    const size = (isCrit ? 28 : 22) * cfg.scale

    Object.assign(el.style, {
      position: 'fixed',
      left: x + 'px',
      top: y + 'px',
      transform: 'translate(-50%, -50%)',
      fontFamily: 'Cinzel, ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto',
      fontWeight: '900',
      fontSize: size + 'px',
      letterSpacing: (isCrit ? 0.5 : 0.3) + 'px',
      color: isCrit ? '#ff4d4d' : '#ffd76a', // red for crit, gold for normal
      textShadow: isCrit
        ? '0 2px 0 #5b0000, 0 0 24px rgba(255,0,0,.35)'
        : '0 2px 0 #7a5c00, 0 0 14px rgba(255,215,106,.35)',
      pointerEvents: 'none',
      zIndex: 9999,
      willChange: 'transform, opacity'
    })

    document.body.appendChild(el)

    // small random arc
    const dx = (Math.random() * 80 - 40) * cfg.scale
    const dy = -(30 + Math.random() * 50) * cfg.scale
    const dur = 900 + ((Math.random() * 900) | 0)

    el.animate(
      [
        { transform: 'translate(-50%,-50%) scale(' + (isCrit ? 1.2 : 1) + ')', opacity: 1 },
        { transform: `translate(calc(-50% + ${dx}px), calc(-50% + ${dy}px)) scale(${isCrit ? 1.6 : 1.2})`, opacity: 0 }
      ],
      { duration: dur, easing: 'cubic-bezier(.2,.8,.2,1)' }
    ).onfinish = () => el.remove()
  }

  document.addEventListener('click', (e) => {
    if (cfg.leftButtonOnly && e.button !== 0) return
    if (window.getSelection?.().toString()) return              // don't fire while selecting text
    if (isInteractive(e.target)) return                         // skip UI controls
    spawnNumber(e.clientX, e.clientY)
  })
})()
