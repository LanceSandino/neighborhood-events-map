/**
 * @module assets/js/lib/render
 * @description HTML fragments for house cards, tags, selected-home panel and pins. Every
 *     resident-supplied string goes through esc(). Mirrors templates/home/_card.html.twig.
 */
import { statusOf, time12 } from './time.js';

export const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

export const isScary = (h) => h.jump_scares || h.scare_level === 'intense';
export const symbolOf = (h) => (isScary(h) ? '👻' : h.low_noise ? '🍭' : '🎃');
export const pinClass = (h) => (isScary(h) ? 'scary' : h.low_noise ? 'quiet' : '');

export function tagsHtml(h) {
  const t = [];
  if (h.candy) t.push(['🍬 Candy', 'candy']);
  if (h.low_noise) t.push(['💚 Low noise', 'quiet']);
  if (h.nonfood) t.push(['💙 Non-food', 'teal']);
  if (h.scare_level === 'intense') t.push(['👻 Intense scares', 'spooky']);
  else if (h.scare_level === 'mild') t.push(['🎃 Mild scares', 'spooky']);
  if (h.jump_scares) t.push(['😱 Jump scares', 'spooky']);
  if (h.flashing_lights) t.push(['⚡ Flashing', 'strobe']);
  if (h.loud_audio) t.push(['🔊 Loud audio', 'strobe']);
  if (h.adult_drinks) t.push(['🍺 21+', 'drinks']);
  if (h.stroller_friendly) t.push(['👶 Stroller-friendly', 'quiet']);
  return t.map(([label, cls]) => `<span class="tag ${cls}">${label}</span>`).join('');
}

export function metaLine(h, ctx) {
  const zone = ctx.zoneLabels[h.zone];
  return `${zone ? esc(zone) + ' · ' : ''}${time12(h.start)}–${time12(h.end)}`;
}

export function cardHtml(h, ctx, { saved, active }) {
  const st = statusOf(h, ctx);
  const statusChip = st.key === 'later' && !st.label.startsWith('Starts') ? '' : `<span class="card-status ${st.key}">${esc(st.label)}</span>`;
  return `<li class="home-card${active ? ' active' : ''}" data-id="${h.id}" tabindex="0" aria-label="${esc(h.address)}">
    <div class="card-symbol" aria-hidden="true">${symbolOf(h)}</div>
    <div class="card-main">
      <div class="card-line"><b>${esc(h.address)}</b><button type="button" class="save" data-save="${h.id}" aria-pressed="${saved}" aria-label="${saved ? 'Remove from' : 'Save to'} my stops: ${esc(h.address)}">${saved ? '♥' : '♡'}</button></div>
      <div class="card-meta">${metaLine(h, ctx)} ${statusChip}</div>
      <div class="tags">${tagsHtml(h)}</div>
      ${h.note ? `<p class="card-note">${esc(h.note)}</p>` : ''}
    </div>
  </li>`;
}

export function selectedHtml(h, ctx, saved) {
  const st = statusOf(h, ctx);
  return `<button type="button" class="x" data-close-selected aria-label="Close">×</button>
    <b>${esc(h.address)}</b>
    <p>${metaLine(h, ctx)}${st.key === 'later' && !st.label.startsWith('Starts') ? '' : ` · <span class="card-status ${st.key}">${esc(st.label)}</span>`}</p>
    ${h.note ? `<p>${esc(h.note)}</p>` : ''}
    <div class="tags">${tagsHtml(h)}</div>
    <button type="button" class="primary" data-save="${h.id}" aria-pressed="${saved}">${saved ? '♥ Saved' : '♡ Save this stop'}</button>
    <p><button type="button" class="link-button" data-report="${h.id}">Report this listing</button></p>`;
}

export function pinHtml(h, { dim, number }) {
  return `<div class="pin ${pinClass(h)}${dim ? ' dim' : ''}${h.status !== 'active' ? ' inactive' : ''}"><span>${isScary(h) ? '👻' : h.low_noise ? '🍭' : '🎃'}</span>${number ? `<i class="pin-number">${number}</i>` : ''}</div>`;
}

/** Leaflet style for a zone outline; with a selected zone, it stands out and the others recede. */
export function zoneStyle(z, selected) {
  if (z.status === 'coming_soon') return { color: '#a59cb2', weight: 1, dashArray: '2 6', fill: true, fillColor: '#a59cb2', fillOpacity: selected ? 0.04 : 0.10, opacity: selected ? 0.5 : 0.9 };
  if (selected && z.key === selected) return { color: '#f07936', weight: 3, dashArray: null, fill: true, fillColor: '#f07936', fillOpacity: 0.12, opacity: 1 };
  if (selected) return { color: '#7d8f86', weight: 1, dashArray: '4 6', fill: true, fillColor: '#1c1b29', fillOpacity: 0.10, opacity: 0.5 };
  return { color: z.residential ? '#7d8f86' : '#a59cb2', weight: 1, dashArray: '4 6', fill: !z.residential, fillOpacity: 0.06, opacity: 1 };
}
