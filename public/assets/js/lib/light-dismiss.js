/**
 * @module assets/js/lib/light-dismiss
 * @description Backdrop click closes a modal <dialog>. Browsers with `closedby` do it natively for
 *     <dialog closedby="any">; elsewhere (Safari) a press and a release both outside the dialog's box close it.
 *     The pointer position is tested, not the event target: the dialog is also the target of clicks on its own
 *     padding and the gaps between its children.
 * @dependencies none
 */

/** True when the point (x, y) is outside the rectangle (a DOMRect or any {left, right, top, bottom}). */
export function outsideRect(rect, x, y) {
  return x < rect.left || x > rect.right || y < rect.top || y > rect.bottom;
}

/** True when the browser closes <dialog closedby="any"> on a backdrop click by itself. */
export const nativeClosedBy = typeof HTMLDialogElement !== 'undefined' && 'closedBy' in HTMLDialogElement.prototype;

/** Makes a backdrop click close `dialog` where `closedby` is not supported (no-op where it is, or when done before). */
export function lightDismiss(dialog) {
  if (!dialog || nativeClosedBy || dialog.dataset.lightDismiss) return;
  dialog.dataset.lightDismiss = '1';
  const outside = (e) => outsideRect(dialog.getBoundingClientRect(), e.clientX, e.clientY);
  let pressedOutside = false;
  dialog.addEventListener('pointerdown', (e) => { pressedOutside = outside(e); });
  // The press and the release both outside, so a text-selection drag that ends on the backdrop does not close it.
  dialog.addEventListener('pointerup', (e) => { if (pressedOutside && outside(e)) dialog.close(); pressedOutside = false; });
}
