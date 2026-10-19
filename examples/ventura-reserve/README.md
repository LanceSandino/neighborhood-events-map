# Ventura Reserve (Orlando, FL) — real-data example

A small, real subdivision in the City of Orlando, Orange County, Florida, used to verify the whole pipeline against official records: county plat polygons → zones and boundary → approval gate → address registry → claims. It is **an example configuration only**. It is not run by or for the community, its residents or its HOA, and it is not a real event.

## What is committed

| File | Content |
|---|---|
| `community.yaml` | Community settings: three zones (Phase 1–3), registry address mode, no walkable groups asserted |
| `gis-sources.json` | Source config for both importers (layer URLs, name rules, field mapping) |
| `boundaries/phase-{1,2,3}.geojson`, `zones.geojson` | One recorded plat per phase, with plat book/page and instrument number |
| `boundaries/boundary.geojson` | Union of the three plats (`approved: false`) |
| `boundaries/source-all.geojson`, `manifest.json` | Unmodified source features, inventory, validity, overlap and area checks, response SHA-256 digests |
| `boundaries/review-map.html` | Standalone review map (open in a browser) |

**Not committed:** listings, claims, emails or the address inventory. The address points are public county records, but this repository never ships an address roster; fetch them yourself (below) into the git-ignored `deploy/private/` folder.

## Sources

- **Plats:** Orange County FL open-data map service `AGOL_Open_Data`, layer 54 *Subdivision* (recorded plats; boundaries maintained by the Orange County Property Appraiser): `https://ocgis4.ocfl.net/arcgis/rest/services/AGOL_Open_Data/MapServer/54`. Query: `NAME LIKE 'VENTURA RESERVE%'` → *VENTURA RESERVE PH 1* (Plat Book 30, Page 37), *PH 2* (31/86), *PH 3* (32/109).
- **Address points:** the same service, layer 0 *Address Points* (Central Addressing System): `ADDRESS_STATUS = 'Active' AND ADDRESS_TYPE = 'Structure'`, kept only when inside the boundary.

The service describes itself as "data made available in a public service by Orange County FL". Plat polygons are a cadastral representation, not a legal survey. Check the county's current terms before reusing the data elsewhere.

## Import results (2026-10-19)

- 3 features, all valid, all expected phases found, nothing unclassified, no spelling variants.
- No pairwise overlaps; the union is one part with no holes (≈ 132,700 m²).
- 152 active structure address points inside the boundary on 5 streets (private CSV, not committed).

## Reproduce

From the repository root (Python 3.10+ with `shapely>=2`, or the Docker one-liner in `AGENTS.md`):

```bash
python3 scripts/fetch_arcgis_boundaries.py --config examples/ventura-reserve/gis-sources.json
python3 scripts/fetch_arcgis_addresses.py  --config examples/ventura-reserve/gis-sources.json
```

Then run the app with `COMMUNITY_BOOTSTRAP_FILE=/app/examples/ventura-reserve/community.yaml`, import the private CSV and review the outline before approving it:

```bash
docker compose cp deploy/private/ventura-reserve-example/addresses.csv app:/tmp/addresses.csv
docker compose exec app php bin/console app:addresses:import /tmp/addresses.csv --dry-run
docker compose exec app php bin/console app:addresses:import /tmp/addresses.csv
```

House listings stay closed until an organizer presses **Approve boundary** in *Organizer → Boundary & zones*. No pedestrian crossings are verified, so each phase is planned as its own trip.
