OpenTripPlanner input data for Phase 1. Not committed to git (see `.gitignore`) — regenerate/download as needed.

Run `./setup.sh` from this folder to fetch and prepare everything below automatically (needs `osmium-tool`: `brew install osmium-tool` on macOS).

- `gtfs-jeepney-bus.zip` — jeepney/bus/train feed from [sakayph/gtfs](https://github.com/sakayph/gtfs), repackaged flat for OTP. Includes MRT-3. Its `calendar.txt` service window originally expired 2020-06-30 (2013-era hackathon data) — the setup script patches `end_date` forward so today's date falls inside the recurring weekly schedule; without that patch OTP refuses to build with "no trips within the configured transit service period."
- `metro-manila.osm.pbf` — street network, clipped from the full [Geofabrik Philippines extract](https://download.geofabrik.de/asia/philippines.html) with `osmium extract -b 120.85,14.25,121.20,14.85`. The full-country file (603MB) OOM'd the graph builder even with a 4GB heap; this bbox covers NCR plus the near edges of Bulacan/Cavite/Laguna/Rizal (matching `aboutus.md`'s stated coverage area) at ~86MB, which builds cleanly.
- `manila.zip` — **not fetched automatically.** LRT-1/2 and PNR feed from [TUMI Datahub](https://hub.tumidata.org/dataset/gtfs-manila) — that host was unreachable when this was set up. Download it manually and drop it in this folder if you want those lines routable too (MRT-3 already works without it).

To (re)build and serve the graph once Docker is running:

```
docker compose up
```

OTP scans this folder for `.osm.pbf` files and `.zip` GTFS feeds, builds a graph from whatever it finds, and serves it on `http://localhost:8080`.
