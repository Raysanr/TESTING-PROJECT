OpenTripPlanner input data for Phase 1. Not committed to git (see `.gitignore`) — regenerate/download as needed.

Run `./setup.sh` from this folder to fetch and prepare everything below automatically (needs `osmium-tool`: `brew install osmium-tool` on macOS).

- `gtfs-jeepney-bus.zip` — jeepney/bus/train feed from [sakayph/gtfs](https://github.com/sakayph/gtfs), repackaged flat for OTP. Includes MRT-3. Its `calendar.txt` service window originally expired 2020-06-30 (2013-era hackathon data) — the setup script patches `end_date` forward so today's date falls inside the recurring weekly schedule; without that patch OTP refuses to build with "no trips within the configured transit service period."
- `metro-manila.osm.pbf` — street network, clipped from the full [Geofabrik Philippines extract](https://download.geofabrik.de/asia/philippines.html) with `osmium extract -b 120.85,14.25,121.20,14.85`. The full-country file (603MB) OOM'd the graph builder even with a 4GB heap; this bbox covers NCR plus the near edges of Bulacan/Cavite/Laguna/Rizal (matching `aboutus.md`'s stated coverage area) at ~86MB, which builds cleanly.
- `manila.zip` — **not fetched automatically.** LRT-1/2 and PNR feed from [TUMI Datahub](https://hub.tumidata.org/dataset/gtfs-manila) — that host was unreachable when this was set up. Download it manually and drop it in this folder if you want those lines routable too (MRT-3 already works without it).

## Map-matching (optional, fixes zigzag transit-leg rendering)

`gtfs-jeepney-bus.zip`'s `shapes.txt` is sparse (521 points across the whole feed), so OTP draws straight lines between waypoints instead of following roads. `php artisan gtfs:map-match` fixes this two ways and rewrites `gtfs-jeepney-bus.zip` in place — run it once, after `setup.sh` and before `docker compose up`:

- **Bus/jeepney shapes** (`route_type` 3) are snapped against OTP's own street router (CAR-mode routing) — needs `docker compose up` running first.
- **MRT-3, LRT-1, and LRT-2** are replaced with real surveyed track geometry extracted from `metro-manila.osm.pbf` (via `osmium`, which `setup.sh` already requires) — CAR-mode street routing would be wrong for a train, since it doesn't run on roads. Both directions of each line are corrected to the right orientation automatically (derived from each shape's own trip in `stop_times.txt`, not hardcoded).
- **PNR** has no single clean OSM route relation covering its extent, so its shapes are left as the source feed provides them — beyond the automatic direction-orientation correction above, which applies to every rail shape uniformly.

```
./setup.sh
docker compose up   # OTP needs to be running for the bus/jeepney matching step
php artisan gtfs:map-match
docker compose restart   # rebuild the graph from the matched shapes
```

Requires `osmium-tool` (`brew install osmium-tool` on macOS, already a `setup.sh` prerequisite) and `otp-data/metro-manila.osm.pbf` to be present — the command checks both and fails with an actionable message if either is missing.

**Do not run it twice in a row** — it isn't idempotent against its own output (feeding already-matched shapes back through it over-subdivides them). It detects this and refuses to run (unless `--force`); if you need a pristine feed again, re-run `./setup.sh` first, which repacks `shapes.txt` from the original source.

To (re)build and serve the graph once Docker is running:

```
docker compose up
```

OTP scans this folder for `.osm.pbf` files and `.zip` GTFS feeds, builds a graph from whatever it finds, and serves it on `http://localhost:8080`.
