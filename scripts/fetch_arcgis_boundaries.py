#!/usr/bin/env python3
"""Download recorded subdivision polygons from an ArcGIS layer into reviewed zone layers.

@module fetch_arcgis_boundaries
@description Optional, config-driven source adapter: inventories, validates and
    classifies official recorded-plat polygons from any ArcGIS MapServer/FeatureServer
    layer into per-zone review layers, a residential zones layer and a derived boundary.
@input  --config gis-sources JSON (``boundaries`` section); live HTTPS ArcGIS REST layer
@output <out>/<source_all_filename>, one <zone_key>.geojson per classified zone,
        zones.geojson, boundary.geojson, manifest.json, review-map.html
@dependencies shapely>=2, Python 3.10+, scripts/arcgis_common.py, scripts/arcgis_config.py

Usage:  python3 scripts/fetch_arcgis_boundaries.py --config deploy/private/<community>/gis-sources.json [--out DIR]

All community specifics (layer URL, ``where`` clause, optional ``variant_where``
spelling-variant query, field names, zones, ordered classification rules, expected
zones, sliver threshold, output directory) live in the config; see
examples/gis-sources.example.json. Keep the real config in the git-ignored
deploy/private/ tree. Relative paths resolve against the current directory.

Classification: the name field is upper-cased and whitespace-collapsed, the
``name_normalize`` substitutions run in order, then ``rules`` run in order and the
first matching rule (``match``: "search" default, or "fullmatch") names the zone.
No match means ``unclassified``. Rules may target ``unclassified`` to deny names.

Data is NOT automatically approved for homeowner registration: review it on the
map. Do not publish third-party GIS data until its reuse terms are checked.

Robustness contract: HTTP status, content type and ArcGIS ``error`` objects are
checked; malformed JSON is retried then reported; ``exceededTransferLimit`` is an
error; raw response SHA-256 digests are recorded; invalid geometries are
inventoried with a reason and excluded from the union (never repaired); pairwise
overlap and pre/post-dissolve areas are reported; sliver holes are removed from
the derived union only; output is oriented per RFC 7946; non-residential zones
and unclassified records get their own layers.
"""
from __future__ import annotations

import argparse
import hashlib
import html
import json
import math
import re
import sys
from collections import defaultdict
from datetime import datetime, timezone
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))  # sibling helpers, also under python -I
from arcgis_common import make_fetch, write_json  # noqa: E402
from arcgis_config import UNCLASSIFIED, BoundarySource, load_boundary_source  # noqa: E402

USER_AGENT = "NeighborhoodHalloweenGISImport/2.0 (noncommercial; ArcGIS feature query)"


def classify(name: str, src: BoundarySource) -> str:
    """Map an exact recorded name to a configured zone key. Strict by design."""
    n = re.sub(r"\s+", " ", (name or "").strip().upper())
    for pattern, replacement in src.normalize:
        n = pattern.sub(replacement, n)
    for pattern, mode, zone_key in src.rules:
        if (pattern.fullmatch(n) if mode == "fullmatch" else pattern.search(n)):
            return zone_key
    return UNCLASSIFIED


def collection(features: list[dict], **extra) -> dict:
    return {"type": "FeatureCollection", **extra, "features": features}


def review_map_html(layers: dict[str, dict], title: str, description: str) -> str:
    """Self-contained Leaflet review page (for local owner review only)."""
    colors = ["#f07936", "#524077", "#40967b", "#d36c64", "#3b82f6", "#a16207", "#0f766e", "#9333ea", "#64748b"]
    payload = json.dumps(layers, separators=(",", ":")).replace("<", "\\u003c")  # no </script> breakout
    return f"""<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{html.escape(title)}</title>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<style>html,body,#map{{height:100%;margin:0;font:14px system-ui}}#note{{position:absolute;z-index:999;left:50px;top:10px;background:#fff;padding:8px 12px;border-radius:8px;box-shadow:0 2px 8px #0003;max-width:60%}}</style>
</head><body><div id="note"><b>Review only — approved: false.</b> {html.escape(description)} Plat boundaries may not equal HOA eligibility. Toggle layers top-right.</div><div id="map"></div>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const layers={payload};const colors={json.dumps(colors)};
const map=L.map('map');L.tileLayer('https://tile.openstreetmap.org/{{z}}/{{x}}/{{y}}.png',{{maxZoom:19,attribution:'&copy; OpenStreetMap contributors'}}).addTo(map);
const overlays={{}};let all=L.featureGroup();let i=0;
for(const [name,fc] of Object.entries(layers)){{const c=colors[i++%colors.length];const lyr=L.geoJSON(fc,{{style:{{color:c,weight:2,fillOpacity:name==='boundary'?0.03:0.18,dashArray:name==='boundary'?'6 6':null}},onEachFeature:(f,l)=>l.bindPopup('<b>'+(f.properties.label||name)+'</b><br>'+(f.properties.source_name||'')+'<br>OBJECTID '+(f.properties.source_objectid??'')+'<br>Plat book '+(f.properties.plat_book??'')+' p. '+(f.properties.plat_pages??''))}});overlays[name]=lyr;if(name!=='boundary')lyr.addTo(map);all.addLayer(lyr);}}
L.control.layers(null,overlays,{{collapsed:false}}).addTo(map);map.fitBounds(all.getBounds());
</script></body></html>
"""


def check_geometry(geometry: dict | None, bounds: tuple[float, float, float, float] | None):
    """Return (shapely geometry or None, reason-or-None). Never repairs."""
    from shapely.geometry import shape
    from shapely.validation import explain_validity

    if not geometry:
        return None, "missing geometry"
    try:
        geom = shape(geometry)
    except Exception as ex:  # noqa: BLE001 - malformed source geometry is inventoried, not fatal
        return None, f"unparseable geometry: {ex}"
    if geom.geom_type not in ("Polygon", "MultiPolygon"):
        return geom, f"unexpected geometry type {geom.geom_type}"
    if geom.is_empty or geom.area <= 0:
        return geom, "empty or zero-area polygon"
    if not geom.is_valid:
        return geom, f"invalid: {explain_validity(geom)}"
    x0, y0, x1, y1 = geom.bounds
    if bounds is not None:
        lo_x, lo_y, hi_x, hi_y = bounds
        if not (lo_x < x0 < hi_x and lo_x < x1 < hi_x and lo_y < y0 < hi_y and lo_y < y1 < hi_y):
            return geom, f"outside expected_bounds_lonlat {list(bounds)} (check [longitude, latitude] order)"
    elif not (-180 <= x0 <= x1 <= 180 and -90 <= y0 <= y1 <= 90):
        return geom, "outside WGS84 range (check [longitude, latitude] order)"
    return geom, None


def area_m2(geom) -> float:
    """Approximate planar area in square metres (local equirectangular projection)."""
    from shapely.ops import transform

    lat0 = math.radians(geom.centroid.y)
    kx, ky = 111_320.0 * math.cos(lat0), 110_540.0
    return transform(lambda x, y, z=None: (x * kx, y * ky), geom).area


def drop_sliver_holes(geom, max_m2: float):
    """Documented derived-geometry cleanup: remove union holes smaller than max_m2.

    Adjacent recorded plats digitized separately leave hairline gaps that become
    near-zero-area holes after dissolve. Only the derived union is changed; the
    per-zone source polygons are written exactly as returned.
    """
    from shapely.geometry import MultiPolygon, Polygon

    removed = 0
    parts = []
    for poly in getattr(geom, "geoms", [geom]):
        keep = []
        for ring in poly.interiors:
            if area_m2(Polygon(ring)) < max_m2:
                removed += 1
            else:
                keep.append(ring)
        parts.append(Polygon(poly.exterior, keep))
    return (parts[0] if len(parts) == 1 else MultiPolygon(parts)), removed


def orient_any(geom):
    """RFC 7946 orientation: exterior rings counter-clockwise, holes clockwise."""
    from shapely.geometry import MultiPolygon
    from shapely.geometry.polygon import orient

    if geom.geom_type == "Polygon":
        return orient(geom, sign=1.0)
    if geom.geom_type == "MultiPolygon":
        return MultiPolygon([orient(p, sign=1.0) for p in geom.geoms])
    raise RuntimeError(f"Union produced unexpected {geom.geom_type}")


def fetch_sources(src: BoundarySource, fetch) -> dict:
    """Run the ID, variant and paged geometry queries; verify every ID arrives exactly once."""
    oid_f, name_f = src.objectid_field, src.name_field
    ids, ids_sha = fetch({"where": src.where, "returnIdsOnly": "true", "f": "json"})
    object_ids = sorted(set(ids.get("objectIds") or []))
    if not object_ids:
        raise RuntimeError(f"No matching source IDs for where={src.where!r}: inspect layer and query before continuing.")
    extra_variants = []
    if src.variant_where:
        variants, _ = fetch({"where": src.variant_where, "outFields": f"{oid_f},{name_f}", "returnGeometry": "false", "f": "json"})
        rows = sorted(((f.get("attributes") or {}).get(oid_f), (f.get("attributes") or {}).get(name_f))
                      for f in variants.get("features") or [])
        extra_variants = [{oid_f: oid, name_f: name} for oid, name in rows if oid not in object_ids]
    originals: list[dict] = []
    page_hashes: list[str] = []
    for at in range(0, len(object_ids), src.batch_size):
        section = object_ids[at: at + src.batch_size]
        page, digest = fetch({"objectIds": ",".join(map(str, section)), "outFields": "*",
                              "returnGeometry": "true", "outSR": 4326, "f": "geojson"})
        if page.get("type") != "FeatureCollection":
            raise RuntimeError(f"Unexpected ArcGIS response for OBJECTIDs {section}: {str(page)[:450]}")
        page_hashes.append(digest)
        originals.extend(page.get("features") or [])
    returned = [src.oid(f) for f in originals]
    if len(returned) != len(set(returned)) or sorted(returned) != object_ids:
        raise RuntimeError(f"Not all IDs fetched exactly once! requested={object_ids} returned={sorted(returned)}")
    originals.sort(key=src.oid)
    return {"object_ids": object_ids, "ids_sha": ids_sha, "page_hashes": page_hashes,
            "extra_variants": extra_variants, "originals": originals}


def build(src: BoundarySource, out: Path, fetch=None) -> dict:
    from shapely.geometry import Polygon, mapping
    from shapely.ops import unary_union

    fetch = fetch or make_fetch(src.layer_url, USER_AGENT)
    got = fetch_sources(src, fetch)
    object_ids, originals = got["object_ids"], got["originals"]
    out.mkdir(parents=True, exist_ok=True)
    hashes = {src.source_all_filename: write_json(out / src.source_all_filename, collection(originals))}

    groups: dict[str, list[dict]] = defaultdict(list)
    shapes: dict[int, object] = {}
    inventory: list[dict] = []
    for f in originals:
        props = f.get("properties") or {}
        name = str(props.get(src.name_field) or "").strip()
        oid = src.oid(f)
        zone = src.zones[classify(name, src)]
        geom, problem = check_geometry(f.get("geometry"), src.bounds)
        if problem is None:
            shapes[oid] = geom
        pages = "-".join(str(props.get(p)) for p in src.plat_page_fields if props.get(p) not in (None, ""))
        item = {"type": "Feature", "id": oid, "geometry": f.get("geometry"), "properties": {
            "zone_key": zone.key, "label": zone.label, "source_name": name, "source_objectid": oid,
            **{out_key: props.get(field) for out_key, field in src.extra_properties.items()},
            "plat_book": props.get(src.plat_book_field) if src.plat_book_field else None, "plat_pages": pages,
            "claimable": zone.claimable and problem is None, "residential": zone.residential,
            "validity_problem": problem, "source_layer": src.layer_url,
        }}
        groups[zone.key].append(item)
        has_geom = geom is not None and not geom.is_empty
        inventory.append({src.objectid_field: oid, src.name_field: name,
                          **{field: props.get(field) for field in src.inventory_fields},
                          "classification": zone.key, "valid": problem is None, "validity_problem": problem,
                          "area_m2_approx": round(area_m2(geom), 1) if has_geom else None,
                          "bounds_lonlat": [round(v, 7) for v in geom.bounds] if has_geom else None})

    for key, features in sorted(groups.items()):
        hashes[f"{key}.geojson"] = write_json(out / f"{key}.geojson", collection(features))

    residential = [item for key in src.residential for item in groups.get(key, [])
                   if item["properties"]["validity_problem"] is None]
    if not residential:
        raise RuntimeError("No valid residential zones were classified. Nothing will be approved.")
    hashes["zones.geojson"] = write_json(out / "zones.geojson", collection(residential))

    # Pairwise overlap check among all valid features (shared edges have zero area).
    overlaps = []
    valid_ids = sorted(shapes)
    for i, a in enumerate(valid_ids):
        for b in valid_ids[i + 1:]:
            inter = shapes[a].intersection(shapes[b])
            if not inter.is_empty and inter.area > 0:
                overlaps.append({"a": a, "b": b, "overlap_m2_approx": round(area_m2(inter), 2)})

    res_shapes = [shapes[it["properties"]["source_objectid"]] for it in residential]
    union, slivers_removed = drop_sliver_holes(unary_union(res_shapes), src.sliver_m2)
    union = orient_any(union)
    sum_area = sum(area_m2(s) for s in res_shapes)
    union_area = area_m2(union)
    hashes["boundary.geojson"] = write_json(out / "boundary.geojson", collection([{
        "type": "Feature", "properties": {"name": src.boundary_name, "approved": False, "zone_key": None,
                                          "source_objectids": [it["properties"]["source_objectid"] for it in residential],
                                          "excludes": src.excluded},
        "geometry": mapping(union)}]))

    review = {name: json.loads((out / f"{name}.geojson").read_text(encoding="utf-8")) for name in [*sorted(groups), "boundary"]}
    (out / "review-map.html").write_text(review_map_html(review, src.review_title, src.source_description), encoding="utf-8")
    hashes["review-map.html"] = hashlib.sha256((out / "review-map.html").read_bytes()).hexdigest()

    parts = list(getattr(union, "geoms", [union]))
    manifest = {
        "source": src.query_url, "source_layer": src.layer_url,
        "queried_at_utc": datetime.now(timezone.utc).isoformat(timespec="seconds"),
        "where": src.where, "variant_where": src.variant_where,
        "variant_records_not_in_main_query": got["extra_variants"],
        "coordinate_order": "[longitude, latitude]", "crs": "EPSG:4326 (RFC 7946)",
        "source_count": len(object_ids), "source_OBJECTIDs": object_ids,
        "ids_response_sha256": got["ids_sha"], "geometry_response_sha256": got["page_hashes"],
        "inventory": sorted(inventory, key=lambda r: (r[src.name_field], r[src.objectid_field])),
        "groups": {k: len(v) for k, v in sorted(groups.items())},
        "missing_expected": [k for k in src.expected if k not in groups],
        "unclassified_count": len(groups.get(UNCLASSIFIED, [])),
        "geometry_checks": {
            "all_valid": all(r["valid"] for r in inventory),
            "pairwise_overlaps": overlaps,
            "residential_sum_area_m2_approx": round(sum_area, 1),
            "residential_union_area_m2_approx": round(union_area, 1),
            "union_parts": len(parts),
            "union_holes": sum(len(p.interiors) for p in parts),
            "union_hole_areas_m2_approx": sorted(round(area_m2(Polygon(r)), 1) for p in parts for r in p.interiors),
            "sliver_holes_removed": slivers_removed,
            "sliver_hole_threshold_m2": src.sliver_m2,
        },
        "requires_owner_review": [
            f"OBJECTID {o['a']} and {o['b']} overlap by ~{o['overlap_m2_approx']} m2; a point there is ambiguous between zones"
            for o in overlaps if o["overlap_m2_approx"] >= src.overlap_review_m2
        ] + list(src.owner_review_checks),
        "notes": list(src.notes),
        "approved": False, "checksums_sha256": hashes,
    }
    manifest_sha = write_json(out / "manifest.json", manifest, compact=False)
    return {"manifest": manifest, "manifest_sha256": manifest_sha}


def main(argv: list[str] | None = None, fetch=None) -> int:
    parser = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    parser.add_argument("--config", type=Path, required=True, help="gis-sources JSON (see examples/gis-sources.example.json)")
    parser.add_argument("--out", type=Path, help="output directory (default: boundaries.out_dir from the config)")
    args = parser.parse_args(argv)
    src = load_boundary_source(args.config)
    out = args.out or src.out_dir
    result = build(src, out, fetch=fetch)
    m = result["manifest"]
    print(f"Downloaded {m['source_count']} official features. Group counts: {m['groups']}")
    print(f"Missing expected: {m['missing_expected']}; unclassified: {m['unclassified_count']}; "
          f"variant records outside main query: {m['variant_records_not_in_main_query']}")
    print(f"Geometry checks: {json.dumps(m['geometry_checks'])}")
    print("Inventory by exact recorded name / ID / classification:")
    for row in m["inventory"]:
        print(json.dumps(row, ensure_ascii=False))
    print(f"Saved to {out} (manifest sha256 {result['manifest_sha256']}) — NOT APPROVED for live address claims.")
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception as exc:  # noqa: BLE001 - CLI boundary
        print(f"ERROR: {exc}", file=sys.stderr)
        sys.exit(1)
