"""
@module scripts/tests/test_drill_listing
@description The drill helper's pure parts: proof-of-work bit counting (as the server counts) and the pin it places
    for a new home (inside the boundary, farthest from every listed home, the same every run).
@dependencies scripts/drill_listing.py, unittest
"""
import hashlib
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import drill_listing as d  # noqa: E402


class DrillHelperTest(unittest.TestCase):
    def test_leading_zero_bits_and_solving(self):
        self.assertEqual(d.leading_zero_bits(bytes([0, 0, 0x10]) + bytes(29)), 19)
        self.assertEqual(d.leading_zero_bits(bytes(32)), 256)
        c = {"id": "abc", "salt": "s" * 32, "difficulty": 8}
        nonce = d.solve("login", c)
        self.assertGreaterEqual(d.leading_zero_bits(hashlib.sha256(f"login:abc:{'s' * 32}:{nonce}".encode()).digest()), 8)

    def test_the_pin_is_inside_and_away_from_homes(self):
        ring = [[-81.688, 29.199], [-81.6805, 29.199], [-81.6805, 29.2026], [-81.6825, 29.2038], [-81.688, 29.2038], [-81.688, 29.199]]
        homes = [(-81.6873, 29.1998), (-81.6850, 29.2010), (-81.6820, 29.2000)]
        lon, lat = d.pick_pin(ring, homes)
        self.assertTrue(d.inside(lon, lat, ring))
        self.assertGreater(min(d.metres((lon, lat), h) for h in homes), 50)
        self.assertEqual((lon, lat), d.pick_pin(ring, homes), "deterministic")
        self.assertFalse(d.inside(-81.6806, 29.2037, ring), "the cut corner is outside")
        with self.assertRaises(ValueError):
            d.pick_pin([[0, 0], [0, 0], [0, 0]], homes)


if __name__ == "__main__":
    unittest.main()
