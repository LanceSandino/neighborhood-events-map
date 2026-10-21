#!/usr/bin/env python3
"""Build a PRIVATE address inventory CSV from an official ArcGIS address-point layer.

@module fetch_arcgis_addresses
@description Optional, config-driven deployment adapter: downloads official address
    points inside a reviewed boundary, assigns zone keys by zone polygon and writes a
    CSV for `bin/console app:addresses:import`. Never geocodes or invents coordinates.
@input  --config gis-sources JSON (``addresses`` section); live HTTPS ArcGIS REST point layer;
        <boundaries>/boundary.geojson and <boundaries>/zones.geojson from fetch_arcgis_boundaries.py
@output <out>/addresses.csv (PRIVATE: git-ignored, never published) and <out>/addresses-manifest.json
@dependencies shapely>=2, Python 3.10+, scripts/arcgis_common.py, scripts/arcgis_config.py

Usage:  python3 scripts/fetch_arcgis_addresses.py --config deploy/private/<community>/gis-sources.json \
            [--boundaries DIR] [--out DIR]

Defaults: --boundaries is addresses.boundaries_dir, else boundaries.out_dir, else
deploy/private/boundaries; --out is addresses.out_dir, else deploy/private/addresses.
Keep the output under the git-ignored deploy/private/ tree.

``addresses.fields`` maps roles to the layer's field names. The display address is
house_number, prefix_direction, street_name, street_type, suffix_direction, then
unit_type unit_number; full_address is the fallback when those are empty. Only the
listed address fields are requested and fields named like "owner" are refused. Rows
are de-duplicated by upper-cased display address (blank or repeated addresses are
counted, not written). Points in overlapping zones are written without a zone_key.
"""
from __future__ import annotations

import argparse
import csv
import hashlib
import io
import json
import sys
from datetime import datetime, timezone
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))  # sibling helpers, also under python -I
from arcgis_common import make_fetch  # noqa: E402
from arcgis_config import AddressSource, load_address_source  # noqa: E402

USER_AGENT = "NeighborhoodEventsMapAddressImport/2.0 (noncommercial; ArcGIS feature query)"
CSV_COLUMNS = ["address", "latitude", "longitude", "zone_key", "county_address_id", "zip"]
STREET_ROLES = ("house_number", "prefix_direction", "street_name", "street_type", "suffix_direction")
UNIT_ROLES = ("unit_type", "unit_number")


def load_shape(path: Path):
    from shapely.geometry import shape
    from shapely.ops import unary_union

    fc = json.loads(path.read_text(encoding="utf-8"))
    return unary_union([shape(f["geometry"]) for f in fc["features"]])


def _value(attrs: dict, fields: dict, role: str) -> str:
    field = fields.get(role)
    return str(attrs.get(field) or "").strip() if field else ""


def _raw(attrs: dict, fields: dict, role: str):
    field = fields.get(role)
    return (attrs.get(field) or "") if field else ""


def display_address(attrs: dict, fields: dict) -> str:
    """Compose 'NUMBER [DIR] STREET [TYPE] [DIR] [UNIT]' from mapped fields, else full_address."""
    street = " ".join(p for p in (_value(attrs, fields, r) for r in STREET_ROLES) if p)
    unit = " ".join(p for p in (_value(attrs, fields, r) for r in UNIT_ROLES) if p)
    return (street + (" " + unit if unit else "")).strip() or _value(attrs, fields, "full_address")


def build(src: AddressSource, boundaries: Path, out: Path, fetch=None) -> dict:
    from shapely.geometry import Point, shape

    fetch = fetch or make_fetch(src.layer_url, USER_AGENT, timeout=60.0)
    boundary = load_shape(boundaries / "boundary.geojson")
    zones_fc = json.loads((boundaries / "zones.geojson").read_text(encoding="utf-8"))
    zones = [(f["properties"]["zone_key"], shape(f["geometry"])) for f in zones_fc["features"]]
    x0, y0, x1, y1 = boundary.bounds
    ids, ids_sha = fetch({"where": src.where, "geometry": f"{x0},{y0},{x1},{y1}", "geometryType": "esriGeometryEnvelope",
                          "inSR": 4326, "spatialRel": "esriSpatialRelIntersects", "returnIdsOnly": "true", "f": "json"})
    object_ids = sorted(ids.get("objectIds") or [])
    rows, outside, ambiguous, no_zone, dupes, fetched = [], 0, 0, 0, 0, 0
    seen: set[str] = set()
    page_hashes: list[str] = []
    for at in range(0, len(object_ids), src.batch_size):
        page, digest = fetch({"objectIds": ",".join(map(str, object_ids[at:at + src.batch_size])),
                              "outFields": ",".join(src.out_fields), "returnGeometry": "true", "outSR": 4326, "f": "json"})
        page_hashes.append(digest)
        for feat in page.get("features") or []:
            fetched += 1
            a, g = feat.get("attributes") or {}, feat.get("geometry") or {}
            if "x" not in g or "y" not in g:
                continue
            p = Point(g["x"], g["y"])
            if not boundary.covers(p):
                outside += 1
                continue
            hits = [k for k, s in zones if s.covers(p)]
            if len(hits) > 1:
                ambiguous += 1
            if not hits:
                no_zone += 1
            addr = display_address(a, src.fields)
            key = addr.upper()
            if not addr or key in seen:
                dupes += 1
                continue
            seen.add(key)
            rows.append({"address": addr, "latitude": f"{g['y']:.7f}", "longitude": f"{g['x']:.7f}",
                         "zone_key": hits[0] if len(hits) == 1 else "",
                         "county_address_id": _raw(a, src.fields, "address_id"), "zip": _raw(a, src.fields, "zip")})
    if fetched != len(object_ids):
        raise RuntimeError(f"Fetched {fetched} of {len(object_ids)} address points; refusing partial inventory.")
    rows.sort(key=lambda r: (r["zone_key"], r["address"]))
    out.mkdir(parents=True, exist_ok=True)
    buf = io.StringIO()
    writer = csv.DictWriter(buf, fieldnames=CSV_COLUMNS, lineterminator="\n")
    writer.writeheader()
    writer.writerows(rows)
    (out / "addresses.csv").write_text(buf.getvalue(), encoding="utf-8")
    by_zone: dict[str, int] = {}
    for r in rows:
        by_zone[r["zone_key"] or "(ambiguous/none)"] = by_zone.get(r["zone_key"] or "(ambiguous/none)", 0) + 1
    streets = sorted({" ".join(r["address"].split(" ")[1:]).split(" UNIT ")[0] for r in rows})
    manifest = {
        "source_layer": src.layer_url, "queried_at_utc": datetime.now(timezone.utc).isoformat(timespec="seconds"),
        "where": src.where, "boundaries_dir": str(boundaries),
        "ids_response_sha256": ids_sha, "page_response_sha256": page_hashes,
        "bbox_query_points": len(object_ids), "inside_boundary": len(rows) + dupes, "written": len(rows),
        "outside_boundary": outside, "ambiguous_zone_points": ambiguous, "no_zone_points": no_zone,
        "duplicate_or_blank_addresses": dupes, "by_zone": dict(sorted(by_zone.items())),
        "distinct_streets": streets, "fields_requested": list(src.out_fields), "field_mapping": dict(src.fields),
        "owner_names_requested": False,
        "csv_sha256": hashlib.sha256(buf.getvalue().encode()).hexdigest(), "approved": False,
        "notes": ["PRIVATE inventory: never publish or commit addresses.csv.",
                  "Address points are official address-authority locations; they are not proof of residence or eligibility.",
                  "Points in overlapping plat areas are written without a zone_key for organizer review."],
    }
    (out / "addresses-manifest.json").write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")
    return manifest


def main(argv: list[str] | None = None, fetch=None) -> int:
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("--config", type=Path, required=True, help="gis-sources JSON (see examples/gis-sources.example.json)")
    ap.add_argument("--boundaries", type=Path, help="directory with boundary.geojson and zones.geojson")
    ap.add_argument("--out", type=Path, help="output directory (keep it under deploy/private/)")
    args = ap.parse_args(argv)
    src = load_address_source(args.config)
    out = args.out or src.out_dir
    m = build(src, args.boundaries or src.boundaries_dir, out, fetch=fetch)
    print(json.dumps({k: v for k, v in m.items() if k != "distinct_streets"}, indent=2))
    print(f"{len(m['distinct_streets'])} distinct streets. Wrote {out}/addresses.csv (PRIVATE).")
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception as exc:  # noqa: BLE001 - CLI boundary
        print(f"ERROR: {exc}", file=sys.stderr)
        sys.exit(1)
