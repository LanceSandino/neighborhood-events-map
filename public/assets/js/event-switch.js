/**
 * @module assets/js/event-switch
 * @description The event switcher (ADR 0011) on a narrow screen: the strip scrolls inside its own box, so the page's
 *     own event is brought into view, and a soft edge shows on the side where more events are. The links work without
 *     this script; it only helps to see them.
 * @dependencies none
 */
const nav = document.querySelector('.event-switch');
if (nav) {
  const current = nav.querySelector('[aria-current="page"]');
  if (current) {
    const box = nav.getBoundingClientRect();
    const tab = current.getBoundingClientRect();
    if (tab.left < box.left || tab.right > box.right) nav.scrollLeft += tab.left - box.left - (box.width - tab.width) / 2;
  }
  const edges = () => {
    nav.classList.toggle('more-after', nav.scrollLeft + nav.clientWidth < nav.scrollWidth - 2);
    nav.classList.toggle('more-before', nav.scrollLeft > 2);
  };
  nav.addEventListener('scroll', edges, { passive: true });
  addEventListener('resize', edges);
  edges();
}
