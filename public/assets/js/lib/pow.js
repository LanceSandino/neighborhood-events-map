/**
 * @module assets/js/lib/pow
 * @description Requests a PoW challenge for a purpose and solves it in a Web Worker.
 * @output Promise<{id, nonce}> for the request body's "pow" field.
 * @dependencies lib/api.js, pow-worker.js
 */
import { api } from './api.js';

export async function solvePow(purpose, onProgress = () => {}) {
  const { challenge } = await api(`/api/pow?purpose=${encodeURIComponent(purpose)}`);
  if (typeof Worker === 'undefined') {
    throw new Error('This browser cannot run the anti-spam check. Please try a current browser.');
  }
  return new Promise((resolve, reject) => {
    const worker = new Worker('/assets/js/pow-worker.js');
    const timer = setTimeout(() => { worker.terminate(); reject(new Error('The anti-spam check took too long. Please try again.')); }, 110000);
    worker.onmessage = (e) => {
      if (e.data.progress !== undefined) { onProgress(e.data.progress); return; }
      clearTimeout(timer);
      worker.terminate();
      onProgress(1);
      resolve({ id: challenge.id, nonce: e.data.nonce });
    };
    worker.onerror = (err) => { clearTimeout(timer); worker.terminate(); reject(new Error('Anti-spam check failed: ' + err.message)); };
    worker.postMessage(challenge);
  });
}
