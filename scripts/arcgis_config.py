#!/usr/bin/env python3
"""Load and validate the gis-sources JSON config used by the ArcGIS importer scripts.

@module arcgis_config
@description Schema and validation for the ``boundaries`` and ``addresses`` sections
    of a gis-sources config: rejects unknown keys, unsafe zone keys / file names,
    invalid regexes, non-http(s) URLs and owner-name fields; applies documented defaults.
@input  A gis-sources JSON file (see examples/gis-sources.example.json)
@output Frozen BoundarySource / AddressSource objects
@dependencies Python 3.10+ standard library, scripts/arcgis_common.py

Usage:  imported by scripts/fetch_arcgis_boundaries.py and scripts/fetch_arcgis_addresses.py.
"""
from __future__ import annotations

import json
import re
import sys
from dataclasses import dataclass
from pathlib import Path
from typing import Any

sys.path.insert(0, str(Path(__file__).resolve().parent))  # sibling helper, also under python -I
from arcgis_common import query_url  # noqa: E402

FIELD = re.compile(r"^[A-Za-z_][A-Za-z0-9_.]*$")
UNCLASSIFIED = "unclassified"
DEFAULT_OUT = Path("deploy/private/boundaries")
ZONE_KEY = re.compile(r"^[a-z0-9]+(?:-[a-z0-9]+)*$")
RESERVED = {"zones", "boundary", "manifest", "review-map"}
FIXED_PROPS = {"zone_key", "label", "source_name", "source_objectid", "plat_book", "plat_pages",
               "claimable", "residential", "validity_problem", "source_layer"}
KEYS = {"layer_url", "where", "variant_where", "name_field", "objectid_field", "plat_book_field",
        "plat_page_fields", "extra_properties", "zones", "name_normalize", "rules", "expected_zones",
        "sliver_hole_threshold_m2", "overlap_review_threshold_m2", "batch_size", "expected_bounds_lonlat",
        "out_dir", "source_all_filename", "boundary_name", "review_title", "source_description",
        "notes", "owner_review_checks"}


def load_config(path: Path) -> dict:
    """Read a gis-sources JSON config; keys starting with ``_`` are comments and ignored."""
    try:
        data = json.loads(Path(path).read_text(encoding="utf-8"))
    except FileNotFoundError:
        raise RuntimeError(f"Config file not found: {path}") from None
    except json.JSONDecodeError as ex:
        raise RuntimeError(f"Config {path} is not valid JSON: {ex}") from None
    if not isinstance(data, dict):
        raise RuntimeError(f"Config {path} must be a JSON object")
    return data


def config_section(config: dict, name: str, allowed: set[str], required: tuple[str, ...]) -> dict:
    """Return config[name], rejecting unknown (likely misspelt) keys and missing required keys."""
    section = config.get(name)
    if not isinstance(section, dict):
        raise RuntimeError(f"Config needs a {name!r} object")
    unknown = sorted(k for k in section if not k.startswith("_") and k not in allowed)
    if unknown:
        raise RuntimeError(f"Unknown key(s) in config {name!r}: {unknown}")
    missing = [k for k in required if section.get(k) in (None, "")]
    if missing:
        raise RuntimeError(f"Config {name!r} is missing required key(s): {missing}")
    return section


def typed(section: dict, key: str, kind: type | tuple, default: Any, where: str) -> Any:
    """Typed optional config value (bool is not accepted where a number is expected)."""
    value = section.get(key)
    if value is None:
        return default
    kinds = kind if isinstance(kind, tuple) else (kind,)
    if (isinstance(value, bool) and bool not in kinds) or not isinstance(value, kinds):
        names = "/".join(k.__name__ for k in kinds)
        raise RuntimeError(f"Config {where}.{key} must be {names}, got {type(value).__name__}")
    return value


def field_name(value: Any, where: str) -> str:
    """An ArcGIS field name (letters, digits, '_' and '.'); rejects anything else."""
    if not isinstance(value, str) or not FIELD.match(value):
        raise RuntimeError(f"Config {where} must be an ArcGIS field name, got {value!r}")
    return value


@dataclass(frozen=True)
class Zone:
    key: str
    label: str
    residential: bool
    claimable: bool


@dataclass(frozen=True)
class BoundarySource:
    """Validated ``boundaries`` config section."""
    layer_url: str
    query_url: str
    where: str
    variant_where: str | None
    name_field: str
    objectid_field: str
    plat_book_field: str | None
    plat_page_fields: tuple
    extra_properties: dict
    zones: dict
    normalize: tuple
    rules: tuple
    expected: tuple
    sliver_m2: float
    overlap_review_m2: float
    batch_size: int
    bounds: tuple | None
    out_dir: Path
    source_all_filename: str
    boundary_name: str
    review_title: str
    source_description: str
    notes: tuple
    owner_review_checks: tuple

    @property
    def residential(self) -> list[str]:
        return [k for k, z in self.zones.items() if z.residential]

    @property
    def excluded(self) -> list[str]:
        return [k for k, z in self.zones.items() if not z.residential and k != UNCLASSIFIED] + [UNCLASSIFIED]

    @property
    def inventory_fields(self) -> list[str]:
        fields = [*self.extra_properties.values(), self.plat_book_field, *self.plat_page_fields]
        return [f for f in dict.fromkeys(fields) if f]

    def oid(self, feature: dict) -> int:
        return int((feature.get("properties") or {}).get(self.objectid_field, feature.get("id", -1)))


def _strings(sec: dict, key: str, where: str) -> tuple:
    value = typed(sec, key, list, [], where)
    if not all(isinstance(v, str) and v for v in value):
        raise RuntimeError(f"Config {where}.{key} must be a list of non-empty strings")
    return tuple(value)


def _regex(pattern, where: str):
    if not isinstance(pattern, str) or not pattern:
        raise RuntimeError(f"Config {where} needs a non-empty regex 'pattern'")
    try:
        return re.compile(pattern, re.IGNORECASE)
    except re.error as ex:
        raise RuntimeError(f"Config {where} has an invalid regex {pattern!r}: {ex}") from None


def _zones(sec: dict) -> dict:
    raw = sec.get("zones")
    if not isinstance(raw, dict) or not raw:
        raise RuntimeError("Config boundaries.zones must be a non-empty object keyed by zone_key")
    zones: dict[str, Zone] = {}
    for key, z in raw.items():
        if key.startswith("_"):
            continue
        where = f"boundaries.zones.{key}"
        if not ZONE_KEY.match(key) or key in RESERVED:
            raise RuntimeError(f"Config {where}: zone keys are lower-case slugs (a-z, 0-9, '-') and not {sorted(RESERVED)}")
        if not isinstance(z, dict) or set(z) - {"label", "residential", "claimable", "_comment"}:
            raise RuntimeError(f"Config {where} must be an object with label, residential[, claimable]")
        label, residential = z.get("label"), z.get("residential", False)
        if not isinstance(label, str) or not label or not isinstance(residential, bool):
            raise RuntimeError(f"Config {where} needs a string label and a boolean residential")
        claimable = z.get("claimable", residential)
        if not isinstance(claimable, bool) or (claimable and not residential) or (key == UNCLASSIFIED and residential):
            raise RuntimeError(f"Config {where}: claimable must be boolean and only residential zones can be claimable")
        zones[key] = Zone(key, label, residential, claimable)
    zones.setdefault(UNCLASSIFIED, Zone(UNCLASSIFIED, "Unclassified", False, False))
    if not any(z.residential for z in zones.values()):
        raise RuntimeError("Config boundaries.zones needs at least one residential zone")
    return zones


def _rules(sec: dict, zones: dict) -> tuple:
    raw = sec.get("rules")
    if not isinstance(raw, list) or not raw:
        raise RuntimeError("Config boundaries.rules must be a non-empty ordered list")
    rules = []
    for i, rule in enumerate(raw):
        where = f"boundaries.rules[{i}]"
        if not isinstance(rule, dict) or set(rule) - {"pattern", "match", "zone_key", "note", "_comment"}:
            raise RuntimeError(f"Config {where} must be an object with pattern, zone_key[, match, note]")
        mode = rule.get("match", "search")
        if mode not in ("search", "fullmatch"):
            raise RuntimeError(f"Config {where}.match must be 'search' or 'fullmatch'")
        if rule.get("zone_key") not in zones:
            raise RuntimeError(f"Config {where}.zone_key {rule.get('zone_key')!r} is not declared in boundaries.zones")
        rules.append((_regex(rule.get("pattern"), where), mode, rule["zone_key"]))
    return tuple(rules)


def _normalize(sec: dict) -> tuple:
    steps = []
    for i, step in enumerate(typed(sec, "name_normalize", list, [], "boundaries")):
        where = f"boundaries.name_normalize[{i}]"
        if not isinstance(step, dict) or not isinstance(step.get("replacement"), str):
            raise RuntimeError(f"Config {where} must be an object with pattern and replacement strings")
        steps.append((_regex(step.get("pattern"), where), step["replacement"]))
    return tuple(steps)


def _bounds(sec: dict) -> tuple | None:
    b = sec.get("expected_bounds_lonlat")
    if b is None:
        return None
    ok = (isinstance(b, list) and len(b) == 4 and all(isinstance(v, (int, float)) and not isinstance(v, bool) for v in b)
          and -180 <= b[0] < b[2] <= 180 and -90 <= b[1] < b[3] <= 90)
    if not ok:
        raise RuntimeError("Config boundaries.expected_bounds_lonlat must be [min_lon, min_lat, max_lon, max_lat]")
    return tuple(float(v) for v in b)


def parse_boundary_source(config: dict) -> BoundarySource:
    """Validate the ``boundaries`` section of a gis-sources config."""
    sec = config_section(config, "boundaries", KEYS, ("layer_url", "where", "zones", "rules"))
    w = "boundaries"
    zones = _zones(sec)
    expected = _strings(sec, "expected_zones", w) or tuple(k for k, z in zones.items() if z.residential)
    if any(k not in zones for k in expected):
        raise RuntimeError(f"Config boundaries.expected_zones lists undeclared zones: {[k for k in expected if k not in zones]}")
    extra = typed(sec, "extra_properties", dict, {}, w)
    if any(k in FIXED_PROPS or not ZONE_KEY.match(k.replace("_", "-")) for k in extra):
        raise RuntimeError(f"Config boundaries.extra_properties maps new snake_case output names (not {sorted(FIXED_PROPS)}) to source fields")
    for k, v in extra.items():
        field_name(v, f"boundaries.extra_properties.{k}")
    for i, v in enumerate(typed(sec, "plat_page_fields", list, [], w)):
        field_name(v, f"boundaries.plat_page_fields[{i}]")
    source_all = typed(sec, "source_all_filename", str, "source-all.geojson", w)
    if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]*\.geojson", source_all) or source_all[:-8] in {*zones, *RESERVED}:
        raise RuntimeError("Config boundaries.source_all_filename must be a plain *.geojson name not used by another layer")
    batch = typed(sec, "batch_size", int, 25, w)
    sliver = float(typed(sec, "sliver_hole_threshold_m2", (int, float), 5.0, w))
    overlap = float(typed(sec, "overlap_review_threshold_m2", (int, float), 1.0, w))
    if not 1 <= batch <= 1000 or sliver < 0 or overlap < 0:
        raise RuntimeError("Config boundaries: batch_size must be 1-1000 and thresholds must be >= 0")
    non_res = ", ".join(k for k, z in zones.items() if not z.residential and k != UNCLASSIFIED) or "none"
    default_notes = (
        f"Non-residential zones ({non_res}) are excluded from zones.geojson and from the boundary union; they are not claimable.",
        "Unclassified records are never silently omitted from the inventory.",
        "Recorded plat polygons may not match actual HOA eligible areas or gated access.",
        "Plat polygons are eligibility geometry, not a pedestrian network; no crossings are implied.",
        "House address points must be acquired separately; none are derived from these polygons.",
        f"{source_all} is the merged geometry responses (sorted by OBJECTID); raw response digests are listed.",
        "Do not redistribute in a public repository until the data owner's reuse terms are confirmed.",
    )
    url = query_url(sec["layer_url"], "boundaries.layer_url")
    return BoundarySource(
        layer_url=url[: -len("/query")], query_url=url,
        where=typed(sec, "where", str, None, w), variant_where=typed(sec, "variant_where", str, None, w) or None,
        name_field=field_name(typed(sec, "name_field", str, "Name", w), "boundaries.name_field"),
        objectid_field=field_name(typed(sec, "objectid_field", str, "OBJECTID", w), "boundaries.objectid_field"),
        plat_book_field=field_name(sec["plat_book_field"], "boundaries.plat_book_field") if sec.get("plat_book_field") else None,
        plat_page_fields=_strings(sec, "plat_page_fields", w), extra_properties=dict(extra),
        zones=zones, normalize=_normalize(sec), rules=_rules(sec, zones), expected=expected,
        sliver_m2=sliver, overlap_review_m2=overlap, batch_size=batch, bounds=_bounds(sec),
        out_dir=Path(typed(sec, "out_dir", str, str(DEFAULT_OUT), w)), source_all_filename=source_all,
        boundary_name=typed(sec, "boundary_name", str, "Recorded residential sections", w),
        review_title=typed(sec, "review_title", str, "GIS boundary review map (NOT APPROVED)", w),
        source_description=typed(sec, "source_description", str, "Official recorded subdivision polygons.", w),
        notes=_strings(sec, "notes", w) or default_notes,
        owner_review_checks=_strings(sec, "owner_review_checks", w)
        or ("Confirm HOA eligibility of every recorded section, gates and access routes before approval.",),
    )


def load_boundary_source(path: Path) -> BoundarySource:
    return parse_boundary_source(load_config(path))


ADDRESS_KEYS = {"layer_url", "where", "objectid_field", "fields", "out_fields", "batch_size", "out_dir", "boundaries_dir"}
FIELD_ROLES = ("address_id", "house_number", "prefix_direction", "street_name", "street_type",
               "suffix_direction", "unit_type", "unit_number", "full_address", "zip")
DEFAULT_ADDRESS_OUT = Path("deploy/private/addresses")


@dataclass(frozen=True)
class AddressSource:
    """Validated ``addresses`` config section."""
    layer_url: str
    query_url: str
    where: str
    fields: dict
    out_fields: tuple
    batch_size: int
    out_dir: Path
    boundaries_dir: Path


def parse_address_source(config: dict) -> AddressSource:
    """Validate the ``addresses`` section; the role -> source-field mapping drives the CSV."""
    sec = config_section(config, "addresses", ADDRESS_KEYS, ("layer_url", "fields"))
    w = "addresses"
    raw = typed(sec, "fields", dict, {}, w)
    unknown = sorted(k for k in raw if not k.startswith("_") and k not in FIELD_ROLES)
    if unknown:
        raise RuntimeError(f"Config addresses.fields has unknown role(s) {unknown}; roles are {list(FIELD_ROLES)}")
    fields = {role: field_name(raw[role], f"addresses.fields.{role}") for role in FIELD_ROLES if raw.get(role)}
    if "full_address" not in fields and not {"house_number", "street_name"} <= set(fields):
        raise RuntimeError("Config addresses.fields needs full_address or both house_number and street_name")
    objectid = field_name(typed(sec, "objectid_field", str, "OBJECTID", w), "addresses.objectid_field")
    listed = typed(sec, "out_fields", list, None, w)
    out_fields = tuple(dict.fromkeys(listed if listed is not None else [objectid, *fields.values()]))
    for i, f in enumerate(out_fields):
        field_name(f, f"addresses.out_fields[{i}]")
        if "owner" in f.lower():
            raise RuntimeError(f"Config addresses.out_fields[{i}] {f!r}: owner fields are never requested")
    missing = [f for f in fields.values() if f not in out_fields]
    if missing:
        raise RuntimeError(f"Config addresses.out_fields must include every mapped field; missing {missing}")
    batch = typed(sec, "batch_size", int, 200, w)
    if not 1 <= batch <= 2000:
        raise RuntimeError("Config addresses.batch_size must be 1-2000")
    b = config.get("boundaries")
    boundaries_out = b.get("out_dir") if isinstance(b, dict) and isinstance(b.get("out_dir"), str) else None
    url = query_url(sec["layer_url"], "addresses.layer_url")
    return AddressSource(
        layer_url=url[: -len("/query")], query_url=url,
        where=typed(sec, "where", str, "1=1", w) or "1=1", fields=fields, out_fields=out_fields, batch_size=batch,
        out_dir=Path(typed(sec, "out_dir", str, str(DEFAULT_ADDRESS_OUT), w)),
        boundaries_dir=Path(typed(sec, "boundaries_dir", str, None, w) or boundaries_out or DEFAULT_OUT),
    )


def load_address_source(path: Path) -> AddressSource:
    return parse_address_source(load_config(path))
