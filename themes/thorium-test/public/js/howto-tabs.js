// howto-tabs.js — simple OS tab switcher
document.querySelectorAll('[data-tabs]').forEach(group => {
  const buttons = group.querySelectorAll('.tab-btn');
  const box = group.parentElement.querySelector(':scope .rounded-xl');
  const panels = box ? box.querySelectorAll('[data-panel]') : [];

  const show = name => {
    buttons.forEach(b => b.classList.toggle('is-active', b.dataset.tab === name));
    panels.forEach(p => p.classList.toggle('hidden', p.dataset.panel !== name));
  };

  buttons.forEach(btn => btn.addEventListener('click', () => show(btn.dataset.tab)));
  show([...buttons].find(b => b.classList.contains('is-active'))?.dataset.tab || buttons[0]?.dataset.tab || 'win');
});
