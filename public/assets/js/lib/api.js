/**
 * @module assets/js/lib/api
 * @description fetch() wrapper with JSON bodies, same-origin credentials, the session CSRF header
 *     (from <meta name="csrf-token">) and typed errors {status, code, message, fields, retryAfter}.
 */
export class ApiError extends Error {
  constructor(status, body) {
    const err = (body && body.error) || {};
    super(err.message || `Request failed (${status})`);
    this.status = status;
    this.code = err.code || 'http_' + status;
    this.fields = err.fields || {};
    this.retryAfter = err.retry_after || null;
    this.body = body;
  }
}

export function csrfToken() {
  const m = document.querySelector('meta[name="csrf-token"]');
  return m ? m.getAttribute('content') : '';
}

export async function api(url, { method = 'GET', body, headers = {}, background = false } = {}) {
  const h = { Accept: 'application/json', ...headers };
  if (body !== undefined && !(body instanceof FormData)) h['Content-Type'] = 'application/json';
  if (method !== 'GET') { const t = csrfToken(); if (t) h['X-CSRF-Token'] = t; }
  if (background) h['X-NHM-Background'] = '1';
  const res = await fetch(url, {
    method, headers: h, credentials: 'same-origin',
    body: body === undefined ? undefined : (body instanceof FormData ? body : JSON.stringify(body)),
  });
  const text = await res.text();
  let data = null;
  try { data = text ? JSON.parse(text) : null; } catch { data = { error: { message: 'Unexpected response from the server.' } }; }
  if (!res.ok) throw new ApiError(res.status, data);
  return data;
}

export function friendlyError(e) {
  if (e instanceof ApiError) {
    if (e.status === 429) {
      const mins = e.retryAfter ? Math.ceil(e.retryAfter / 60) : null;
      return mins ? `Too many tries. Please wait about ${mins} minute${mins === 1 ? '' : 's'} and try again.` : e.message;
    }
    return e.message;
  }
  return (e && e.message) || 'Something went wrong. Please try again.';
}
