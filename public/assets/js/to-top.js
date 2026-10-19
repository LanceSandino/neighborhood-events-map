/**
 * @module assets/js/to-top
 * @description Small "back to top" button that appears once a long page has been scrolled about a
 *     screen and a half; it scrolls up (instantly with reduced motion) and moves focus to the content.
 */
const button = document.getElementById('toTop');
if (button) {
  const reduced = () => document.documentElement.dataset.motion === 'reduce' || matchMedia('(prefers-reduced-motion: reduce)').matches;
  let ticking = false;
  const update = () => { button.hidden = scrollY < innerHeight * 1.5; ticking = false; };
  addEventListener('scroll', () => { if (!ticking) { ticking = true; requestAnimationFrame(update); } }, { passive: true });
  button.addEventListener('click', () => {
    scrollTo({ top: 0, behavior: reduced() ? 'auto' : 'smooth' });
    document.getElementById('main')?.focus({ preventScroll: true });
  });
  update();
}
