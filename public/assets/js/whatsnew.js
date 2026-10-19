/**
 * @module assets/js/whatsnew
 * @description Opens the "What's new" dialog (release notes + source link) from the version link in
 *     the footer/sidebar. Without JavaScript the link opens the /changelog page instead.
 */
const dialog = document.getElementById('whatsNew');
if (dialog && typeof dialog.showModal === 'function') {
  document.addEventListener('click', (e) => {
    if (e.target.closest('[data-whats-new]')) { e.preventDefault(); dialog.showModal(); return; }
    if (e.target.closest('[data-whats-new-close]')) { dialog.close(); return; }
    if (e.target === dialog) { // a click on the backdrop (outside the dialog box) closes it
      const r = dialog.getBoundingClientRect();
      if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) dialog.close();
    }
  });
}
