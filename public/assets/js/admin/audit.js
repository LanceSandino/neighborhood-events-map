/**
 * @module assets/js/admin/audit
 * @description Read-only audit log (claims, edits, moderation, transfers, config, sign-ins).
 */
import { $, call, esc } from './common.js';

(async () => {
  const { events } = await call('/api/admin/audit?limit=500');
  $('#admin-audit').innerHTML = `<div class="panel table-wrap"><table class="data"><thead><tr><th>When (UTC)</th><th>Actor</th><th>Action</th><th>Target</th><th>Details</th><th>Source</th></tr></thead><tbody>
    ${events.map((e) => `<tr><td>${esc(e.created_at)}</td><td>${esc(e.actor_label)}</td><td><b>${esc(e.action)}</b></td><td>${esc(e.target_type)} ${esc(e.target_id ?? '')}</td><td><code>${esc(e.details)}</code></td><td>${esc(e.source)}</td></tr>`).join('') || '<tr><td colspan="6">No events yet.</td></tr>'}
  </tbody></table></div>`;
})();
