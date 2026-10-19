/*
 * @module assets/js/theme-init
 * @description Applies the visitor's appearance choices before the page paints (no flash): theme
 *     (light/dark; none = follow the system), larger text, more contrast, reduced motion. Classic blocking
 *     script in <head> (the CSP allows no inline scripts). Stored on this device only ("nhm:appearance").
 */
(function () {
  var p = null;
  try { p = JSON.parse(localStorage.getItem('nhm:appearance') || 'null'); } catch (e) { /* blocked or damaged: defaults */ }
  if (!p || typeof p !== 'object') return;
  var root = document.documentElement;
  var allowed = { theme: ['light', 'dark'], text: ['large'], contrast: ['more'], motion: ['reduce'] };
  for (var k in allowed) if (allowed[k].indexOf(p[k]) >= 0) root.setAttribute('data-' + k, p[k]);
  if (p.theme === 'light' || p.theme === 'dark') {
    var metas = document.querySelectorAll('meta[name="theme-color"]');
    for (var i = 0; i < metas.length; i++) metas[i].setAttribute('content', p.theme === 'dark' ? '#15141d' : '#f8f7f4');
  }
})();
