// howto-accordion.js — close siblings when one <details> opens
document.querySelectorAll('[data-accordion] details').forEach(d => {
  d.addEventListener('toggle', () => {
    if (!d.open) return;
    d.parentElement.querySelectorAll('details').forEach(other => {
      if (other !== d) other.open = false;
    });
  });
});
