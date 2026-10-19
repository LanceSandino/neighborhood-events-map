"""Unit tests for the generic ArcGIS address-point importer (no network; fake ArcGIS layer).

@module test_fetch_arcgis_addresses
@description Verifies field mapping, boundary filtering, duplicate/blank handling,
    ambiguous zones, partial-fetch refusal, query parameters, output location and the
    private-by-default paths of scripts/fetch_arcgis_addresses.py. Synthetic data only.
@dependencies shapely, unittest
"""
import contextlib
import copy
import csv
import io
import json
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "scripts"))
import arcgis_config  # noqa: E402
import fetch_arcgis_addresses as addr  # noqa: E402

CONFIG = {"addresses": {
    "layer_url": "https://gis.example.gov/arcgis/rest/services/Addressing/MapServer/0",
    "where": "Status = 'ACTIVE'",
    "fields": {"address_id": "SiteId", "house_number": "HouseNo", "prefix_direction": "PreDir", "street_name": "StName",
               "street_type": "StType", "unit_type": "UnitKind", "unit_number": "UnitNo", "full_address": "FullAddr",
               "zip": "Zip5"},
    "batch_size": 2,
}}


def source(**overrides):
    cfg = copy.deepcopy(CONFIG)
    cfg["addresses"].update(overrides)
    return arcgis_config.parse_address_source(cfg)


def poly(x0, y0, x1, y1):
    return {"type": "Polygon", "coordinates": [[[x0, y0], [x1, y0], [x1, y1], [x0, y1], [x0, y0]]]}


def write_boundaries(folder: Path) -> Path:
    """Boundary -100.00..-99.99 x 40.00..40.01, split into unit-1 (west) and unit-2 (east) at -99.995."""
    folder.mkdir(parents=True, exist_ok=True)
    fc = lambda feats: json.dumps({"type": "FeatureCollection", "features": feats})  # noqa: E731
    (folder / "boundary.geojson").write_text(fc([{"type": "Feature", "properties": {}, "geometry": poly(-100.0, 40.0, -99.99, 40.01)}]))
    (folder / "zones.geojson").write_text(fc([
        {"type": "Feature", "properties": {"zone_key": "unit-1"}, "geometry": poly(-100.0, 40.0, -99.995, 40.01)},
        {"type": "Feature", "properties": {"zone_key": "unit-2"}, "geometry": poly(-99.995, 40.0, -99.99, 40.01)}]))
    return folder


def attrs(no="", street="", st_type="", pre="", unit_kind="", unit="", full="", site=None, zip5="00001"):
    return {"HouseNo": no, "StName": street, "StType": st_type, "PreDir": pre, "UnitKind": unit_kind, "UnitNo": unit,
            "FullAddr": full, "SiteId": site, "Zip5": zip5, "OwnerName": "MUST NOT LEAK"}


POINTS = {
    1: (attrs(101, "EXAMPLE", "LN", pre="N", unit_kind="APT", unit="2", site=5001), (-99.998, 40.005)),
    2: (attrs(full="300 EXAMPLE CT", site=5002), (-99.992, 40.002)),           # full_address fallback
    3: (attrs(900, "FAR AWAY", "RD", site=5003), (-99.98, 40.005)),            # outside the boundary
    4: (attrs(202, "Example", "Ln", site=5004), (-99.993, 40.008)),
    5: (attrs(202, "EXAMPLE", "LN", site=5005), (-99.993, 40.009)),            # duplicate of 4 (case-insensitive)
    6: (attrs(site=5006), (-99.997, 40.001)),                                  # blank address
    7: (attrs(150, "EDGE", "WAY", site=5007), (-99.995, 40.003)),              # on the unit-1/unit-2 edge
}


class FakeAddressLayer:
    def __init__(self, points, drop_id=None):
        self.points, self.drop_id, self.calls = points, drop_id, []

    def __call__(self, params):
        self.calls.append(params)
        if params.get("returnIdsOnly"):
            return {"objectIds": list(self.points)}, "ids-sha"
        wanted = [int(x) for x in params["objectIds"].split(",") if int(x) != self.drop_id]
        fields = params["outFields"].split(",")
        feats = [{"attributes": {k: v for k, v in self.points[o][0].items() if k in fields},
                  "geometry": {"x": self.points[o][1][0], "y": self.points[o][1][1]}} for o in wanted]
        return {"features": feats}, f"page-{wanted[0] if wanted else 0}"


class AddressBuildTests(unittest.TestCase):
    def run_build(self, points=POINTS, **overrides):
        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        bdir = write_boundaries(Path(tmp.name) / "boundaries")
        out = Path(tmp.name) / "private-out"
        fake = FakeAddressLayer(points, **overrides)
        manifest = addr.build(source(), bdir, out, fetch=fake)
        with (out / "addresses.csv").open(newline="") as fh:
            rows = {r["address"]: r for r in csv.DictReader(fh)}
        return manifest, rows, fake, out

    def test_field_mapping(self):
        m, rows, fake, _ = self.run_build()
        row = rows["101 N EXAMPLE LN APT 2"]
        self.assertEqual((row["zone_key"], row["county_address_id"], row["zip"]), ("unit-1", "5001", "00001"))
        self.assertEqual((row["latitude"], row["longitude"]), ("40.0050000", "-99.9980000"))
        self.assertEqual(rows["300 EXAMPLE CT"]["zone_key"], "unit-2")
        self.assertEqual(m["fields_requested"], ["OBJECTID", "SiteId", "HouseNo", "PreDir", "StName", "StType",
                                                 "UnitKind", "UnitNo", "FullAddr", "Zip5"])
        self.assertFalse(m["owner_names_requested"])
        self.assertTrue(all("OwnerName" not in c["outFields"] for c in fake.calls if "outFields" in c))

    def test_points_outside_boundary_excluded(self):
        m, rows, _, _ = self.run_build()
        self.assertNotIn("900 FAR AWAY RD", rows)
        self.assertEqual((m["bbox_query_points"], m["outside_boundary"]), (7, 1))

    def test_duplicates_and_blanks_counted_not_written(self):
        m, rows, _, _ = self.run_build()
        self.assertEqual([a for a in rows if a.upper() == "202 EXAMPLE LN"], ["202 Example Ln"])  # first one wins
        self.assertEqual(m["duplicate_or_blank_addresses"], 2)
        self.assertEqual(m["written"], len(rows))
        self.assertEqual(m["inside_boundary"], m["written"] + m["duplicate_or_blank_addresses"])

    def test_ambiguous_zone_written_without_zone_key(self):
        m, rows, _, _ = self.run_build()
        self.assertEqual(rows["150 EDGE WAY"]["zone_key"], "")
        self.assertEqual(m["ambiguous_zone_points"], 1)
        self.assertEqual(m["by_zone"]["(ambiguous/none)"], 1)

    def test_query_uses_boundary_envelope_where_and_batches(self):
        m, _, fake, _ = self.run_build()
        first = fake.calls[0]
        self.assertEqual(first["geometry"], "-100.0,40.0,-99.99,40.01")
        self.assertEqual(first["where"], "Status = 'ACTIVE'")
        self.assertEqual(len(fake.calls), 1 + 4)  # 7 ids in batches of 2
        self.assertEqual(m["page_response_sha256"], ["page-1", "page-3", "page-5", "page-7"])

    def test_partial_fetch_refused(self):
        with self.assertRaisesRegex(RuntimeError, "refusing partial inventory"):
            self.run_build(drop_id=4)

    def test_output_lands_where_requested(self):
        with tempfile.TemporaryDirectory() as tmp:
            cfg = Path(tmp) / "gis-sources.json"
            cfg.write_text(json.dumps(CONFIG))
            bdir = write_boundaries(Path(tmp) / "b")
            out = Path(tmp) / "requested" / "dir"
            argv = ["--config", str(cfg), "--boundaries", str(bdir), "--out", str(out)]
            with contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(addr.main(argv, fetch=FakeAddressLayer(POINTS)), 0)
            self.assertEqual(sorted(p.name for p in out.iterdir()), ["addresses-manifest.json", "addresses.csv"])
            self.assertEqual(sorted(p.name for p in bdir.iterdir()), ["boundary.geojson", "zones.geojson"])
            header = (out / "addresses.csv").read_text().splitlines()[0]
            self.assertEqual(header, "address,latitude,longitude,zone_key,county_address_id,zip")


class AddressConfigTests(unittest.TestCase):
    def test_defaults_stay_under_deploy_private(self):
        src = source()
        self.assertEqual(src.out_dir, Path("deploy/private/addresses"))
        self.assertEqual(src.boundaries_dir, Path("deploy/private/boundaries"))
        cfg = copy.deepcopy(CONFIG)
        cfg["boundaries"] = {"out_dir": "deploy/private/somewhere/boundaries"}
        self.assertEqual(arcgis_config.parse_address_source(cfg).boundaries_dir, Path("deploy/private/somewhere/boundaries"))
        example = arcgis_config.load_address_source(ROOT / "examples" / "gis-sources.example.json")
        self.assertTrue(str(example.out_dir).startswith("deploy/private/"))

    def test_invalid_configs_rejected(self):
        for overrides, pattern in (({"out_fields": ["OBJECTID", "HouseNo", "OwnerName"]}, "owner"),
                                   ({"out_fields": ["OBJECTID"]}, "every mapped field"),
                                   ({"fields": {"street_name": "StName"}}, "full_address or both"),
                                   ({"fields": {"full_address": "FullAddr", "house_no": "X"}}, "unknown role"),
                                   ({"fields": {"full_address": "Full Addr"}}, "field name"),
                                   ({"layer_url": "ftp://gis.example.gov/x"}, "http"),
                                   ({"batch_size": 0}, "batch_size")):
            with self.assertRaisesRegex(RuntimeError, pattern):
                source(**overrides)


if __name__ == "__main__":
    unittest.main()
