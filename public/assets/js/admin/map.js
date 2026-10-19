/**
 * @module assets/js/admin/map
 * @description Organizer boundary & zone editor (Leaflet + Leaflet.draw, MIT): draw/edit/delete
 *     polygons as "boundary" or a chosen zone, import/export GeoJSON, preview, save (server validates
 *     rings, order, self-intersection, zones) and approve. Multi-polygons are split into editable parts
 *     and saved back as one FeatureCollection; disconnected areas stay separate features.
 */
import { $, call, esc, flash } from './common.js';

const COLORS = ['#2b8a6e', '#6b4fa0', '#c2410c', '#0369a1', '#a16207', '#be185d', '#4d7c0f', '#7c3aed', '#0f766e', '#b91c1c'];
let data = null;
let map = null;
let drawn = null;
let dirty = false;

const zoneColor = (key) => {
  const i = data.zones.findIndex((z) => z.key === key);
  return (data.zones[i] && data.zones[i].color) || COLORS[i % COLORS.length] || '#555';
};
const styleFor = (p) => (p.kind === 'zone'
  ? { color: zoneColor(p.zone_key), weight: 2, dashArray: '5 5', fillOpacity: 0.12 }
  : { color: '#f07936', weight: 3, fillOpacity: 0.05 });

function shell() {
  $('#admin-map').innerHTML = `
  <div class="editor">
    <div><div class="editor-map" id="editorMap" role="application" aria-label="Boundary editor map"></div>
      <p class="form-caption">Draw on the real map; never trace a screenshot. GeoJSON order is [longitude, latitude]. Disconnected areas are fine — do not bridge across lakes or major roads.</p></div>
    <div class="editor-side">
      <div class="panel"><h2>Status</h2><p id="status"></p>
        <div class="field"><label for="drawAs">New shapes are</label><select id="drawAs"><option value="boundary">Neighborhood boundary</option>
          ${data.zones.map((z) => `<option value="zone:${esc(z.key)}">Zone: ${esc(z.label)}</option>`).join('')}</select></div>
        <p class="form-caption">Use the polygon tool (top-left of the map), then Edit/Delete to adjust vertices.</p>
        <ul class="layer-list" id="layers"></ul>
      </div>
      <div class="panel"><h2>Import / export</h2>
        <div class="field"><label for="importFile">Import GeoJSON file</label><input type="file" id="importFile" accept=".geojson,.json,application/geo+json,application/json"></div>
        <div class="modal-actions"><a class="secondary" href="/api/admin/geometries/export">Export saved GeoJSON</a><button type="button" class="secondary" id="btnPreview">Preview JSON</button></div>
        <pre class="json hide" id="preview"></pre>
      </div>
      <div class="panel"><h2>Save &amp; approve</h2>
        <div class="modal-actions"><button type="button" class="primary" id="btnSave">Save geometry</button></div>
        <label class="check-chip"><input type="checkbox" id="reviewed"> I checked the outline against real streets, property edges, gates and phase/zone edges.</label>
        <div class="modal-actions"><button type="button" class="primary" id="btnApprove">Approve boundary</button><button type="button" class="secondary" id="btnRevoke">Revoke approval</button></div>
        <div id="saveNotes"></div>
      </div>
    </div>
  </div>`;
}

let newShapes = 0;

/** Leaflet.draw edits simple polygons, so MultiPolygons are split into parts that remember their source feature. */
function featuresFromCollection(fc) {
  const out = [];
  (fc.features || []).forEach((f, i) => {
    const g = f.geometry || {};
    const multi = g.type === 'MultiPolygon';
    const parts = multi ? g.coordinates.map((c) => ({ type: 'Polygon', coordinates: c })) : [g];
    for (const part of parts) out.push({ type: 'Feature', properties: { ...f.properties, __fid: `f${i}`, __multi: multi }, geometry: part });
  });
  return out;
}

function addFeature(f) {
  L.geoJSON(f, { style: () => styleFor(f.properties) }).eachLayer((layer) => {
    layer.feature = layer.feature || f;
    layer.feature.properties = { ...f.properties };
    layer.bindTooltip(f.properties.kind === 'zone' ? `Zone: ${esc(f.properties.zone_key)}` : 'Boundary', { sticky: true });
    drawn.addLayer(layer);
  });
}

/** Re-groups parts by source feature so an unchanged import exports byte-for-byte identically. */
function currentCollection() {
  const groups = new Map();
  drawn.eachLayer((layer) => {
    const gj = layer.toGeoJSON(false); // full precision: never round official coordinates
    const props = { ...(layer.feature?.properties || {}) };
    if (!props.__fid) props.__fid = layer.feature.properties.__fid = `n${++newShapes}`;
    if (!groups.has(props.__fid)) groups.set(props.__fid, { props, parts: [] });
    groups.get(props.__fid).parts.push(gj.geometry.coordinates);
  });
  const features = [];
  for (const { props, parts } of groups.values()) {
    const { __fid, __multi, ...clean } = props;
    clean.kind = clean.kind === 'zone' ? 'zone' : 'boundary';
    if (clean.kind === 'boundary') delete clean.zone_key;
    const geometry = parts.length > 1 || __multi ? { type: 'MultiPolygon', coordinates: parts } : { type: 'Polygon', coordinates: parts[0] };
    features.push({ type: 'Feature', properties: clean, geometry });
  }
  return { type: 'FeatureCollection', features };
}

function renderLayers() {
  const counts = {};
  const seen = new Set();
  drawn.eachLayer((l) => { const p = l.feature?.properties || {}; if (seen.has(p.__fid)) return; seen.add(p.__fid); const k = p.kind === 'zone' ? `zone:${p.zone_key}` : 'boundary'; counts[k] = (counts[k] || 0) + 1; });
  $('#layers').innerHTML = Object.entries(counts).map(([k, n]) => `<li><span><i class="swatch" data-color="${k === 'boundary' ? '#f07936' : zoneColor(k.slice(5))}"></i>${esc(k === 'boundary' ? 'Boundary' : 'Zone ' + k.slice(5))}</span><span>${n} area${n === 1 ? '' : 's'}</span></li>`).join('') || '<li>No shapes yet.</li>';
  document.querySelectorAll('#layers .swatch[data-color]').forEach((el) => { el.style.background = el.dataset.color; }); // CSP: no inline style attributes
  $('#status').innerHTML = `${data.version ? `Saved version v${data.version}` : 'Nothing saved yet'} · ${data.approved ? '<span class="ok">approved</span>' : '<span class="warn">not approved — house listings closed</span>'}${dirty ? ' · <span class="warn">unsaved changes</span>' : ''}`;
}

function fit() {
  const b = drawn.getBounds();
  if (b.isValid()) map.fitBounds(b, { padding: [20, 20] });
  else if (data.center) map.setView([data.center.lat, data.center.lon], 15);
  else map.setView([39.5, -98.35], 4);
}

function init() {
  shell();
  map = L.map('editorMap', { zoomSnap: 0.25 });
  L.tileLayer(data.tiles.url, { maxZoom: data.tiles.max_zoom, attribution: esc(data.tiles.attribution), referrerPolicy: 'strict-origin-when-cross-origin' }).addTo(map);
  drawn = new L.FeatureGroup().addTo(map);
  featuresFromCollection(data.geojson).forEach(addFeature);
  map.addControl(new L.Control.Draw({
    edit: { featureGroup: drawn },
    draw: { polygon: { allowIntersection: false, showArea: true }, rectangle: true, polyline: false, circle: false, marker: false, circlemarker: false },
  }));
  map.on(L.Draw.Event.CREATED, (e) => {
    const as = $('#drawAs').value;
    const props = { ...(as === 'boundary' ? { kind: 'boundary' } : { kind: 'zone', zone_key: as.slice(5) }), __fid: `n${++newShapes}` };
    e.layer.feature = { type: 'Feature', properties: props };
    e.layer.setStyle(styleFor(props));
    drawn.addLayer(e.layer);
    dirty = true; renderLayers();
  });
  map.on(L.Draw.Event.EDITED, () => { dirty = true; renderLayers(); });
  map.on(L.Draw.Event.DELETED, () => { dirty = true; renderLayers(); });
  fit();
  renderLayers();

  $('#importFile').addEventListener('change', async (e) => {
    const file = e.target.files[0];
    if (!file) return;
    try {
      const fc = JSON.parse(await file.text());
      const coll = fc.type === 'FeatureCollection' ? fc : { type: 'FeatureCollection', features: [fc.type === 'Feature' ? fc : { type: 'Feature', properties: {}, geometry: fc }] };
      drawn.clearLayers();
      featuresFromCollection(coll).forEach((f) => addFeature({ ...f, properties: { ...f.properties, kind: f.properties.zone_key ? 'zone' : 'boundary' } }));
      dirty = true; fit(); renderLayers();
      flash('Imported into the editor. Review it on the map, then Save. The server validates the geometry.', 'info');
    } catch (err) { flash('That file is not valid JSON/GeoJSON: ' + err.message, 'error'); }
  });
  $('#btnPreview').addEventListener('click', () => { const p = $('#preview'); p.textContent = JSON.stringify(currentCollection(), null, 1).slice(0, 20000); p.classList.toggle('hide'); });
  $('#btnSave').addEventListener('click', () => save(false));
  $('#btnApprove').addEventListener('click', approve);
  $('#btnRevoke').addEventListener('click', async () => { await call('/api/admin/geometries/approve', { method: 'POST', body: { confirm: true, action: 'revoke' } }); flash('Approval revoked; house listings are closed.'); reload(); });
  addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
}

async function save(confirmOutside) {
  try {
    const res = await call('/api/admin/geometries', { method: 'PUT', body: { geojson: currentCollection(), confirm_outside_claims: confirmOutside } });
    dirty = false;
    $('#saveNotes').innerHTML = res.result.warnings.map((w) => `<div class="notice">⚠️ ${esc(w)}</div>`).join('');
    flash(`Saved as version v${res.result.version}. Review it, then approve to open house listings.`);
    reload(false);
  } catch (e) {
    if (e.code === 'boundary_confirmation_required' && confirm(`${e.message}\n\nSave anyway?`)) save(true);
  }
}

async function approve() {
  if (dirty) { flash('Save your changes before approving.', 'error'); return; }
  if (!$('#reviewed').checked) { flash('Confirm that you reviewed the outline first.', 'error'); return; }
  const r = await call('/api/admin/geometries/approve', { method: 'POST', body: { confirm: true } });
  flash(r.registration.open ? 'Boundary approved. House listings are open.' : `Boundary approved. ${r.registration.reason || ''}`);
  reload(false);
}

async function reload(rebuild = true) {
  data = await call('/api/admin/geometries');
  if (rebuild) { drawn.clearLayers(); featuresFromCollection(data.geojson).forEach(addFeature); }
  renderLayers();
}

(async () => { data = await call('/api/admin/geometries'); init(); })();
