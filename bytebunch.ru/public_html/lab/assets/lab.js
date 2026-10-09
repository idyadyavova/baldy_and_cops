/* ByteBunch • Lab — shared behaviour: solid nav after scrolling */
(() => {
  const nav = document.querySelector('.nav');
  if (!nav) return;
  const set = () => nav.setAttribute('data-solid', String(scrollY > 12));
  set();
  addEventListener('scroll', set, { passive: true });
})();
