/**
 * @module assets/js/legal.js
 * @description Privacy page: lets a visitor delete everything this site saved in their browser
 *     (favorites, filters, start point, return path). Nothing is sent to the server.
 */
const btn = document.getElementById('btnClearLocal');
const status = document.getElementById('clearStatus');
btn?.addEventListener('click', () => {
  let removed = 0;
  for (const store of [globalThis.localStorage, globalThis.sessionStorage]) {
    try {
      for (const key of Object.keys(store)) if (key.startsWith('nhm:')) { store.removeItem(key); removed += 1; }
    } catch { /* storage blocked: nothing saved */ }
  }
  status.textContent = removed ? 'Saved favorites and filters were removed from this device.' : 'Nothing was saved on this device.';
});
