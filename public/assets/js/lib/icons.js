/**
 * @module assets/js/lib/icons
 * @description The app's drawn icons in browser-built markup: icon('i-map') is an aria-hidden <svg> using the sprite next
 *     to this module (public/assets/icons/icons.svg under the same versioned asset prefix). Icons are decorative: the
 *     words beside them or the control's own label carry the meaning. Only plain sprite ids draw anything, so an id can
 *     never become markup.
 * @dependencies none
 */
const SPRITE = new URL('../../icons/icons.svg', import.meta.url).pathname;

export const icon = (name, cls = '') => (/^[a-z0-9]+(-[a-z0-9]+)*$/.test(String(name))
  ? `<svg class="icon${cls ? ` ${cls}` : ''}" aria-hidden="true" focusable="false"><use href="${SPRITE}#${name}"></use></svg>`
  : '');
