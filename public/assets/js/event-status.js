/**
 * @module assets/js/event-status
 * @description The page of a public event that shows no homes (ADR 0011): an upcoming event, where a resident may
 *     list a home ("List your home" opens the same sheet as on the map, for this event), or a closed one. Wires the
 *     sheets (rules, My house, listing) and the links to them; there is no map and no list.
 * @dependencies lib/modal.js, register.js
 */
import { initModals, openModal, closeModal } from './lib/modal.js';
import { initRegistration, openRegistration } from './register.js';

const boot = JSON.parse(document.getElementById('boot').textContent);

function actions(action) {
  switch (action) {
    case 'register': if (document.getElementById('registerModal')) { openRegistration(); return true; } return false;
    case 'info': openModal('infoModal'); return true;
    case 'house': openModal('houseModal'); return true;
    case 'explore': window.scrollTo({ top: 0 }); return true;
    default: return false;
  }
}

initModals();
initRegistration({ community: boot.community, onPublishedHint: () => {} });
document.addEventListener('click', (e) => {
  const a = e.target.closest('[data-action]');
  if (!a) return;
  const host = a.closest('dialog.modal[open]');
  if (host && a.dataset.action !== 'explore') closeModal(host);
  if (actions(a.dataset.action)) e.preventDefault();
});
const fromHash = () => {
  const map = { '#list-my-house': 'register', '#info': 'info', '#my-house': 'house' };
  if (map[location.hash]) actions(map[location.hash]);
};
addEventListener('hashchange', fromHash);
fromHash();
