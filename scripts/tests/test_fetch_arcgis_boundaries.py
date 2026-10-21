"""Unit tests for the generic ArcGIS boundary importer (no network; fake ArcGIS fetcher).

@module test_fetch_arcgis_boundaries
@description Verifies config-driven classification, pagination integrity, overlap/area
    checks, sliver-hole cleanup, RFC 7946 orientation and output contracts of
    scripts/fetch_arcgis_boundaries.py, config validation in scripts/arcgis_config.py and
    the strict HTTP helper in scripts/arcgis_common.py. Synthetic names and coordinates only.
@dependencies shapely, unittest
"""
import contextlib
import copy
import io
import hashlib
import json
import re
import sys
import tempfile
import unittest
import urllib.error
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "scripts"))
import arcgis_common  # noqa: E402
import arcgis_config  # noqa: E402
import fetch_arcgis_boundaries as gis  # noqa: E402

UNITS = ("1", "2", "3C", "3D")
CONFIG = {"boundaries": {
    "layer_url": "https://gis.example.gov/arcgis/rest/services/Cadastral/MapServer/1",
    "where": "Name LIKE '%EXAMPLE ESTATES%'",
    "variant_where": "UPPER(Name) LIKE '%EXAMPLE%ESTATE%'",
    "plat_book_field": "BookNumber", "plat_page_fields": ["PlatPage1", "PlatPage2"],
    "extra_properties": {"gis_sub_number": "GISSubNumber"},
    "zones": {**{f"unit-{u.lower()}": {"label": f"Unit {u}", "residential": True} for u in UNITS},
              "clubhouse": {"label": "Clubhouse", "residential": False},
              "common-areas": {"label": "Common areas", "residential": False}},
    "name_normalize": [{"pattern": r"\bUNIT\s*(\d+)\s*([A-Z])?\b", "replacement": r"UNIT \1\2"}],
    "rules": [{"pattern": "^(?!EXAMPLE ESTATES)", "zone_key": "unclassified"},
              {"pattern": "CLUBHOUSE", "zone_key": "clubhouse"},
              {"pattern": "COMMON AREA", "zone_key": "common-areas"},
              {"pattern": r"\b(REPLAT|AMENDED|AMENDMENT|PARTIAL)\b", "zone_key": "unclassified"}]
    + [{"pattern": f"EXAMPLE ESTATES UNIT {u}", "match": "fullmatch", "zone_key": f"unit-{u.lower()}"} for u in UNITS],
    "expected_zones": [*(f"unit-{u.lower()}" for u in UNITS), "clubhouse"],
    "expected_bounds_lonlat": [-101.0, 39.0, -99.0, 41.0],
}}


def source(**overrides):
    cfg = copy.deepcopy(CONFIG)
    cfg["boundaries"].update(overrides)
    return arcgis_config.parse_boundary_source(cfg)


def square(x0, y0, size=0.002):
    return {"type": "Polygon", "coordinates": [[[x0, y0], [x0 + size, y0], [x0 + size, y0 + size], [x0, y0 + size], [x0, y0]]]}


class FakeArcGIS:
    def __init__(self, records, drop_id=None, variants=()):
        self.records = records  # oid -> (name, geometry)
        self.drop_id = drop_id
        self.variants = list(variants)
        self.calls = []

    def __call__(self, params):
        self.calls.append(params)
        if params.get("returnIdsOnly"):
            return {"objectIds": list(self.records)}, "ids-sha"
        if params.get("returnGeometry") == "false":
            rows = [(k, v[0]) for k, v in self.records.items()] + self.variants
            return {"features": [{"attributes": {"OBJECTID": k, "Name": n}} for k, n in rows]}, "var"
        wanted = [int(x) for x in params["objectIds"].split(",") if int(x) != self.drop_id]
        feats = [{"type": "Feature", "id": oid, "geometry": self.records[oid][1],
                  "properties": {"OBJECTID": oid, "Name": self.records[oid][0], "BookNumber": 71, "PlatPage1": 1, "PlatPage2": 2}}
                 for oid in wanted]
        return {"type": "FeatureCollection", "features": feats}, f"page-{wanted[0] if wanted else 0}"


def records():
    return {
        1: ("EXAMPLE ESTATES UNIT 1", square(-100.00, 40.00)),
        2: ("EXAMPLE ESTATES UNIT 2", square(-99.998, 40.00)),  # shares an edge with unit 1
        3: ("EXAMPLE ESTATES CLUBHOUSE", square(-99.99, 40.01)),
        4: ("EXAMPLE ESTATES UNIT 5", square(-99.98, 40.01)),
    }


class ClassifyTests(unittest.TestCase):
    def test_expected_names(self):
        src = source()
        self.assertEqual(gis.classify("EXAMPLE ESTATES UNIT 1", src), "unit-1")
        self.assertEqual(gis.classify("EXAMPLE ESTATES UNIT 3C", src), "unit-3c")
        self.assertEqual(gis.classify("Example Estates  Unit 3 D", src), "unit-3d")
        self.assertEqual(gis.classify("EXAMPLE ESTATES CLUBHOUSE", src), "clubhouse")
        self.assertEqual(gis.classify("EXAMPLE ESTATES COMMON AREA TRACT", src), "common-areas")

    def test_unexpected_names_are_unclassified(self):
        src = source()
        for name in ("EXAMPLE ESTATES UNIT 5", "EXAMPLE ESTATES UNIT 3", "EXAMPLE ESTATES UNIT 2 REPLAT",
                     "EXAMPLE ESTATES UNIT 10", "OTHER EXAMPLE ESTATES", "", "EXAMPLE ESTATES"):
            self.assertEqual(gis.classify(name, src), "unclassified", name)


class BuildTests(unittest.TestCase):
    def test_outputs_and_manifest(self):
        with tempfile.TemporaryDirectory() as tmp:
            result = gis.build(source(), Path(tmp), fetch=FakeArcGIS(records()))
            m = result["manifest"]
            self.assertFalse(m["approved"])
            self.assertEqual(m["groups"], {"clubhouse": 1, "unclassified": 1, "unit-1": 1, "unit-2": 1})
            self.assertIn("unit-3c", m["missing_expected"])
            self.assertEqual(m["geometry_checks"]["pairwise_overlaps"], [])
            self.assertEqual(m["geometry_checks"]["union_parts"], 1)  # edge-sharing units dissolve
            zones = json.loads((Path(tmp) / "zones.geojson").read_text())
            self.assertEqual(sorted(f["properties"]["zone_key"] for f in zones["features"]), ["unit-1", "unit-2"])
            for f in zones["features"]:
                for key in ("zone_key", "label", "source_name", "source_objectid", "plat_book", "plat_pages",
                            "claimable", "residential", "gis_sub_number"):
                    self.assertIn(key, f["properties"])
                self.assertEqual(f["properties"]["plat_pages"], "1-2")
            boundary = json.loads((Path(tmp) / "boundary.geojson").read_text())
            props = boundary["features"][0]["properties"]
            self.assertFalse(props["approved"])
            self.assertNotIn(3, props["source_objectids"])
            self.assertEqual(props["excludes"], ["clubhouse", "common-areas", "unclassified"])
            self.assertFalse((Path(tmp) / "unit-3c.geojson").exists())  # no empty misleading files
            club = json.loads((Path(tmp) / "clubhouse.geojson").read_text())
            self.assertFalse(club["features"][0]["properties"]["claimable"])
            self.assertTrue((Path(tmp) / "review-map.html").exists())
            self.assertEqual(sorted(m["checksums_sha256"]), sorted(p.name for p in Path(tmp).iterdir() if p.name != "manifest.json"))

    def test_overlap_detected(self):
        recs = records()
        recs[2] = ("EXAMPLE ESTATES UNIT 2", square(-99.999, 40.00))  # overlaps unit 1 by half
        with tempfile.TemporaryDirectory() as tmp:
            m = gis.build(source(), Path(tmp), fetch=FakeArcGIS(recs))["manifest"]
            self.assertEqual(len(m["geometry_checks"]["pairwise_overlaps"]), 1)
            self.assertLess(m["geometry_checks"]["residential_union_area_m2_approx"],
                            m["geometry_checks"]["residential_sum_area_m2_approx"])

    def test_sliver_holes_removed_but_real_holes_kept(self):
        from shapely.geometry import Polygon
        outer = [(-100.00, 40.00), (-99.99, 40.00), (-99.99, 40.01), (-100.00, 40.01)]
        sliver = [(-99.995, 40.005), (-99.995 + 1e-7, 40.005), (-99.995, 40.005 + 1e-7)]
        real = [(-99.998, 40.002), (-99.997, 40.002), (-99.997, 40.003), (-99.998, 40.003)]
        cleaned, removed = gis.drop_sliver_holes(Polygon(outer, [sliver, real]), 5.0)
        self.assertEqual(removed, 1)
        self.assertEqual(len(cleaned.interiors), 1)

    def test_significant_overlap_requires_review(self):
        recs = records()
        recs[2] = ("EXAMPLE ESTATES UNIT 2", square(-99.999, 40.00))
        with tempfile.TemporaryDirectory() as tmp:
            m = gis.build(source(), Path(tmp), fetch=FakeArcGIS(recs))["manifest"]
            self.assertTrue(any("overlap" in r for r in m["requires_owner_review"]))

    def test_missing_ids_abort(self):
        with tempfile.TemporaryDirectory() as tmp:
            with self.assertRaisesRegex(RuntimeError, "Not all IDs fetched"):
                gis.build(source(), Path(tmp), fetch=FakeArcGIS(records(), drop_id=2))

    def test_out_of_range_geometry_flagged_not_claimable(self):
        recs = records()
        recs[1] = ("EXAMPLE ESTATES UNIT 1", square(40.00, -100.00))  # swapped lat/lon
        with tempfile.TemporaryDirectory() as tmp:
            m = gis.build(source(), Path(tmp), fetch=FakeArcGIS(recs))["manifest"]
            row = next(r for r in m["inventory"] if r["OBJECTID"] == 1)
            self.assertFalse(row["valid"])
            self.assertIn("longitude, latitude", row["validity_problem"])
            self.assertFalse(m["geometry_checks"]["all_valid"])
            unit1 = json.loads((Path(tmp) / "unit-1.geojson").read_text())["features"][0]["properties"]
            self.assertFalse(unit1["claimable"])

    def test_no_ids_raises(self):
        with tempfile.TemporaryDirectory() as tmp:
            with self.assertRaisesRegex(RuntimeError, "No matching"):
                gis.build(source(), Path(tmp), fetch=FakeArcGIS({}))

    def test_invalid_geometry_inventoried_never_repaired(self):
        recs = records()
        bowtie = {"type": "Polygon", "coordinates": [[[-99.97, 40.0], [-99.96, 40.01], [-99.96, 40.0], [-99.97, 40.005], [-99.97, 40.0]]]}
        recs[5] = ("EXAMPLE ESTATES UNIT 3C", bowtie)
        recs[6] = ("EXAMPLE ESTATES UNIT 3D", None)
        with tempfile.TemporaryDirectory() as tmp:
            m = gis.build(source(), Path(tmp), fetch=FakeArcGIS(recs))["manifest"]
            rows = {r["OBJECTID"]: r for r in m["inventory"]}
            self.assertTrue(rows[5]["validity_problem"].startswith("invalid:"))
            self.assertEqual(rows[6]["validity_problem"], "missing geometry")
            self.assertNotIn(5, json.loads((Path(tmp) / "boundary.geojson").read_text())["features"][0]["properties"]["source_objectids"])
            written = json.loads((Path(tmp) / "unit-3c.geojson").read_text())["features"][0]
            self.assertEqual(written["geometry"], bowtie)  # source geometry kept exactly as returned
            self.assertFalse(written["properties"]["claimable"])

    def test_pagination_digests_variants_and_rfc7946_orientation(self):
        from shapely.geometry import shape
        fake = FakeArcGIS(records(), variants=[(99, "EXAMPLE ESTATE UNIT 1 (SPELLING)")])
        with tempfile.TemporaryDirectory() as tmp:
            m = gis.build(source(batch_size=3), Path(tmp), fetch=fake)["manifest"]
            self.assertEqual(m["ids_response_sha256"], "ids-sha")
            self.assertEqual(m["geometry_response_sha256"], ["page-1", "page-4"])
            self.assertEqual(m["variant_records_not_in_main_query"], [{"OBJECTID": 99, "Name": "EXAMPLE ESTATE UNIT 1 (SPELLING)"}])
            union = shape(json.loads((Path(tmp) / "boundary.geojson").read_text())["features"][0]["geometry"])
            self.assertTrue(union.exterior.is_ccw)


class ReassignTests(unittest.TestCase):
    """Lots that are homes inside a non-residential plat move to a residential zone (owner decision)."""
    RULE = {"layer_url": "https://gis.example.gov/arcgis/rest/services/Cadastral/MapServer/15",
            "where": "OBJECTID IN (501, 502)", "from_zone": "clubhouse", "to_zone": "unit-1",
            "note": "Lots 9-10 of the clubhouse plat are homes; their numbering continues Unit 1."}

    @staticmethod
    def lots_layer(lots):
        calls = []

        def factory(url):
            def fetch(params):
                calls.append((url, params))
                feats = [{"type": "Feature", "id": oid, "geometry": g, "properties": {"OBJECTID": oid}} for oid, g in lots.items()]
                return {"type": "FeatureCollection", "features": feats}, "lots-sha"
            return fetch
        return factory, calls

    def recs(self):
        return {1: ("EXAMPLE ESTATES UNIT 1", square(-100.00, 40.00)), 3: ("EXAMPLE ESTATES CLUBHOUSE", square(-99.998, 40.00))}

    def lot(self, x0, y0):
        return {"type": "Polygon", "coordinates": [[[x0, y0], [x0 + 0.0005, y0], [x0 + 0.0005, y0 + 0.002], [x0, y0 + 0.002], [x0, y0]]]}

    def test_lots_move_from_the_amenity_plat_into_the_residential_zone(self):
        factory, calls = self.lots_layer({501: self.lot(-99.998, 40.00), 502: self.lot(-99.9975, 40.00)})
        with tempfile.TemporaryDirectory() as tmp:
            m = gis.build(source(reassign=[self.RULE]), Path(tmp), fetch=FakeArcGIS(self.recs()), fetch_layer=factory)["manifest"]
            self.assertEqual(calls[0][0], self.RULE["layer_url"])
            self.assertEqual(calls[0][1]["where"], "OBJECTID IN (501, 502)")
            self.assertEqual(m["reassignments"][0]["source_objectids"], [501, 502])
            self.assertTrue(any("moved from clubhouse to unit-1" in r and "numbering continues" in r for r in m["requires_owner_review"]))
            self.assertEqual(m["geometry_checks"]["pairwise_overlaps"], [], "the moved lots are no longer in the clubhouse")
            self.assertEqual(m["geometry_checks"]["union_parts"], 1)
            from shapely.geometry import Point, shape
            zones = {f["properties"]["zone_key"]: f for f in json.loads((Path(tmp) / "zones.geojson").read_text())["features"]}
            club = json.loads((Path(tmp) / "clubhouse.geojson").read_text())["features"][0]
            inside_lot = Point(-99.9974, 40.001)
            self.assertTrue(shape(zones["unit-1"]["geometry"]).contains(inside_lot))
            self.assertFalse(shape(club["geometry"]).contains(inside_lot))
            self.assertTrue(shape(club["geometry"]).contains(Point(-99.9965, 40.001)), "the rest of the clubhouse plat stays non-residential")
            self.assertEqual(zones["unit-1"]["properties"]["reassigned_in"][0]["from_zone"], "clubhouse")
            boundary = shape(json.loads((Path(tmp) / "boundary.geojson").read_text())["features"][0]["geometry"])
            self.assertTrue(boundary.contains(inside_lot))

    def test_polygons_outside_the_source_zone_or_missing_abort(self):
        for lots in ({501: self.lot(-99.990, 40.00)}, {}):
            factory, _ = self.lots_layer(lots)
            with tempfile.TemporaryDirectory() as tmp, self.assertRaises(RuntimeError):
                gis.build(source(reassign=[self.RULE]), Path(tmp), fetch=FakeArcGIS(self.recs()), fetch_layer=factory)

    def test_invalid_rules_rejected(self):
        for bad in ({**self.RULE, "to_zone": "common-areas"}, {**self.RULE, "note": " "}, {**self.RULE, "from_zone": "unit-1"},
                    {**self.RULE, "layer_url": "http://insecure.example/MapServer/1"}, {**self.RULE, "extra": 1}):
            with self.assertRaises(RuntimeError, msg=str(bad)):
                source(reassign=[bad])


class ConfigTests(unittest.TestCase):
    def assertRejected(self, pattern, **overrides):
        with self.assertRaisesRegex(RuntimeError, pattern):
            source(**overrides)

    def test_invalid_configs_rejected(self):
        self.assertRejected("Unknown key", wehre="1=1")
        self.assertRejected("http", layer_url="file:///etc/passwd")
        self.assertRejected("lower-case slugs", zones={"../escape": {"label": "X", "residential": True}})
        self.assertRejected("not declared", rules=[{"pattern": "X", "zone_key": "unit-9"}])
        self.assertRejected("invalid regex", rules=[{"pattern": "(", "zone_key": "unit-1"}])
        self.assertRejected("only residential", zones={"unit-1": {"label": "U", "residential": True},
                                                       "pool": {"label": "P", "residential": False, "claimable": True}})
        self.assertRejected("field name", name_field="Name,Owner")
        self.assertRejected("source_all_filename", source_all_filename="../zones.geojson")

    def test_defaults_and_example_config(self):
        self.assertEqual(source().out_dir, Path("deploy/private/boundaries"))
        example = arcgis_config.load_boundary_source(ROOT / "examples" / "gis-sources.example.json")
        self.assertEqual(gis.classify("Example Estates Unit 1", example), "unit-1")
        self.assertEqual(gis.classify("EXAMPLE ESTATES CLUBHOUSE", example), "clubhouse")
        self.assertFalse(example.zones["clubhouse"].claimable)
        self.assertTrue(str(example.out_dir).startswith("deploy/private/"))

    def test_main_writes_to_requested_out(self):
        with tempfile.TemporaryDirectory() as tmp:
            cfg = Path(tmp) / "gis-sources.json"
            cfg.write_text(json.dumps(CONFIG))
            out = Path(tmp) / "elsewhere"
            with contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(gis.main(["--config", str(cfg), "--out", str(out)], fetch=FakeArcGIS(records())), 0)
            self.assertTrue((out / "manifest.json").exists() and (out / "boundary.geojson").exists())


class FakeResponse:
    def __init__(self, body, status=200, ctype="application/json; charset=utf-8"):
        self.body, self.status, self.headers = body, status, {"Content-Type": ctype}

    def read(self):
        return self.body

    def __enter__(self):
        return self

    def __exit__(self, *exc):
        return False


class HttpJsonTests(unittest.TestCase):
    def call(self, queue, sleeps=None):
        def opener(req, timeout):
            self.assertEqual(req.get_header("User-agent"), "test")
            item = queue.pop(0)
            if isinstance(item, Exception):
                raise item
            return item
        return arcgis_common.http_json("https://gis.example.gov/q", {"f": "json"}, user_agent="test",
                                       opener=opener, sleep=(sleeps if sleeps is not None else []).append)

    def test_success_returns_raw_digest(self):
        import hashlib
        data, digest = self.call([FakeResponse(b'{"objectIds":[1]}')])
        self.assertEqual(data, {"objectIds": [1]})
        self.assertEqual(digest, hashlib.sha256(b'{"objectIds":[1]}').hexdigest())

    def test_malformed_json_retried_then_reported(self):
        sleeps = []
        data, _ = self.call([FakeResponse(b"{oops"), urllib.error.URLError("down"), FakeResponse(b"{}")], sleeps)
        self.assertEqual((data, sleeps), ({}, [1, 2]))
        with self.assertRaisesRegex(RuntimeError, "unavailable"):
            self.call([FakeResponse(b"{oops"), FakeResponse(b"{oops"), FakeResponse(b"<html>")])

    def test_errors_and_truncation_raise_without_retry(self):
        for response, pattern in ((FakeResponse(b'{"error":{"code":400}}'), "ArcGIS returned error"),
                                  (FakeResponse(b'{"exceededTransferLimit":true}'), "truncated"),
                                  (FakeResponse(b'{"properties":{"exceededTransferLimit":true}}'), "truncated"),
                                  (FakeResponse(b"{}", ctype="text/html"), "content type"),
                                  (FakeResponse(b"{}", status=500), "HTTP 500"),
                                  (FakeResponse(b"[1]"), "non-object")):
            queue = [response, FakeResponse(b"{}")]
            with self.assertRaisesRegex(RuntimeError, pattern):
                self.call(queue)
            self.assertEqual(len(queue), 1, pattern)  # not retried


class ReviewMapTest(unittest.TestCase):
    """Audit run-1 lead 19: popups were HTML strings built from ArcGIS attributes (third-party data)."""

    def test_committed_example_review_map_is_the_current_generators_output(self):
        # The committed Ventura Reserve review page must not keep an older, unescaped popup builder.
        example = ROOT / "examples" / "ventura-reserve"
        cfg = json.loads((example / "gis-sources.json").read_text(encoding="utf-8"))["boundaries"]
        page = (example / "boundaries" / "review-map.html").read_text(encoding="utf-8")
        names = list(json.loads(re.search(r"const layers=(\{.*?\});const colors=", page, re.S).group(1)))
        layers = {n: json.loads((example / "boundaries" / f"{n}.geojson").read_text(encoding="utf-8")) for n in names}
        regenerated = gis.review_map_html(layers, cfg.get("review_title", "GIS boundary review map (NOT APPROVED)"),
                                          cfg.get("source_description", "Official recorded subdivision polygons."))
        self.assertEqual(regenerated, page, "regenerate the example's review-map.html from its GeoJSON")
        manifest = json.loads((example / "boundaries" / "manifest.json").read_text(encoding="utf-8"))
        self.assertEqual(manifest["checksums_sha256"]["review-map.html"], hashlib.sha256(page.encode("utf-8")).hexdigest())

    def test_popups_are_text_only_and_cdn_assets_are_pinned(self):
        evil = "<img src=x onerror=alert(1)>"
        layers = {"zones": {"type": "FeatureCollection", "features": [
            {"type": "Feature", "properties": {"label": evil, "source_name": evil, "plat_book": evil}, "geometry": None}]}}
        page = gis.review_map_html(layers, "Review & <check>", "desc <b>")
        self.assertNotIn(evil, page)
        self.assertNotIn("bindPopup('<b>'", page, "no popup is assembled from HTML strings")
        self.assertIn(".textContent=", page)
        self.assertIn('integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="', page)
        self.assertIn('integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="', page)
        self.assertIn("Review &amp; &lt;check&gt;", page)

if __name__ == "__main__":
    unittest.main()
