/**
 * @module assets/js/whatsnew
 * @description Opens the "What's new" dialog (release notes + source link) from the version link in
 *     the footer/sidebar. Without JavaScript the link opens the /changelog page instead. A click on the backdrop
 *     closes it (closedby="any", or lib/light-dismiss.js where that is not supported).
 * @dependencies lib/light-dismiss.js
 */
import { lightDismiss } from './lib/light-dismiss.js';

const dialog = document.getElementById('whatsNew');
if (dialog && typeof dialog.showModal === 'function') {
  lightDismiss(dialog);
  document.addEventListener('click', (e) => {
    if (e.target.closest('[data-whats-new]')) { e.preventDefault(); dialog.showModal(); return; }
    if (e.target.closest('[data-whats-new-close]')) dialog.close();
  });
}
