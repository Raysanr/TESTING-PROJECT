# GTFS Shape Map-Matching — Design

## Context

Rendered transit-leg routes on the map (`drawRoute()` in `resources/js/app.js`, decoding OTP's `legGeometry.points`) visibly zigzag instead of following the actual road. Root cause, confirmed by inspecting the feed directly: `otp-data/gtfs-jeepney-bus.zip`'s `shapes.txt` has only 521 total points across the entire feed's shapes. OTP draws straight lines between these sparse points, so on a long corridor like EDSA the line cuts diagonally through blocks between waypoints instead of tracing every bend.

This isn't fixable in application code — `TripPlannerService`, `RouteScorer`, `FareEstimator`, and `drawRoute()` all just pass through whatever geometry OTP returns. The fix has to happen at the data layer: replace the sparse shapes with denser, road-following ones before OTP builds its graph from them.

## Goal

A one-time, local command that turns the GTFS feed's ugly, sparse shapes into clean, road-snapped ones — using infrastructure that already exists (the OTP instance from `docker compose up`), with no new services, no paid APIs, and no ongoing pipeline.

## Scope

**In scope:**
- A single Artisan command that map-matches `otp-data/gtfs-jeepney-bus.zip`'s `shapes.txt` against OTP's own street-routing (verified working: OTP2's `plan` GraphQL query with `transportModes:[{mode: CAR}]` returns a road-snapped polyline between two points, using the OSM graph OTP already has loaded — confirmed against the running local instance).
- Fallback handling for two edge cases, verified against the real feed and the real OTP instance:
  - **Unmatchable pairs** — OTP returns `{"itineraries": []}` (confirmed behavior, not an error) when no drivable connection exists between two points (water crossing, disconnected OSM segment, points too far apart).
  - **Suspicious detours** — a jeepney's real contraflow/counterflow path won't respect standard one-way driving rules; OTP's CAR router may substitute a long legal detour instead of the short informal path. Detected by comparing the matched road-path length to the straight-line (haversine) distance between the input pair; a ratio above a tunable threshold is treated as unreliable.
- Rewriting `gtfs-jeepney-bus.zip` in place with the matched `shapes.txt`, so the next `docker compose up` graph rebuild picks it up automatically — no other step required.

**Explicitly out of scope:**
- Any new infrastructure (OSRM, Valhalla, or any other map-matching service). OTP's own already-running street router is the only "matcher" used.
- Any application code change. `TripPlannerService`, `RouteScorer`, `FareEstimator`, `resources/js/app.js`, and the frontend map rendering are all untouched — they benefit automatically from better input data.
- Running this automatically or on a schedule. It's a manual, local, one-time (or occasionally re-run-when-the-feed-changes) developer tool, the same category as `otp-data/setup.sh`.
- Fixing the underlying GTFS feed's other data-quality issues (expired `calendar.txt`, missing LRT-1/2/PNR feed) — those are already tracked in `otp-data/README.md`.
- True Hidden-Markov-Model map-matching (weighing multiple candidate roads against a full GPS trace). This is a simpler point-to-point routing approach; it's a pragmatic fit given OTP's existing capabilities, not a research-grade map-matcher.

## Architecture

```
otp-data/gtfs-jeepney-bus.zip
        |
        | 1. extract to temp dir, parse shapes.txt
        v
Group rows by shape_id, ordered by shape_pt_sequence
        |
        | 2. for each shape, walk consecutive point pairs
        v
For each pair (lat1,lon1) -> (lat2,lon2):
        |
        |    POST /otp/routers/default/index/graphql
        |    { plan(from:{lat1,lon1}, to:{lat2,lon2},
        |           transportModes:[{mode: CAR}]) { itineraries { legs { legGeometry { points } } } } }
        v
  itineraries empty?  ---yes--> fallback: keep original 2-point segment, log "unmatchable"
        | no
        v
  decode legGeometry.points, compute road-path length
        |
  roadLength / haversine(pair) > DETOUR_RATIO_THRESHOLD? ---yes--> fallback: keep original 2-point
        | no                                                       segment, log "detour_ratio"
        v
  splice decoded points into the shape's sequence, replacing the original 2-point segment
        |
        | 3. after all shapes processed
        v
Reassemble shapes.txt (renumbered shape_pt_sequence), write into extracted GTFS contents
        |
        | 4. re-zip over gtfs-jeepney-bus.zip (temp-copy-then-atomic-overwrite)
        v
Print summary: shapes processed, segments matched, segments fell back (by reason), fallback %
```

## Components

**`app/Console/Commands/MapMatchGtfsShapes.php`** (new) — the entire feature. One command, one responsibility.

- `handle(): int`
  1. Verify OTP is reachable (a quick GraphQL request to `http://localhost:8080`); if not, fail fast with `"OTP isn't running — start it with docker compose up first."` and return a non-zero exit code without touching any files.
  2. Verify `otp-data/gtfs-jeepney-bus.zip` exists and is readable; fail fast with the path if not.
  3. Extract the zip to a temp directory (`Storage::disk('local')` or plain `sys_get_temp_dir()` — implementation detail for the plan). Parse `shapes.txt` into `shape_id => ordered list of {lat, lon}` groups.
  4. For each shape, for each consecutive pair, call OTP's `plan` query (CAR mode), apply the unmatchable/detour-ratio checks above, and build the new point sequence.
  5. Write the new `shapes.txt` into the temp extraction directory.
  6. Re-zip the temp directory's full contents (all original GTFS files, only `shapes.txt` changed) into a new temp zip, then atomically move it over the original `gtfs-jeepney-bus.zip` only after the zip is fully and successfully written — a mid-run crash never leaves a half-written GTFS zip in place.
  7. Print the summary report to the console.

- **Constants** (named, not inline): `DETOUR_RATIO_THRESHOLD = 2.5`, OTP base URL (reuse whatever config key `TripPlannerService` already uses for the OTP endpoint, not a new hardcoded one).

- **Idempotency:** re-running the command re-matches from whatever `shapes.txt` currently exists (already-matched geometry re-matches to essentially the same result, modulo OTP routing determinism), so a partial or repeated run is always safe to re-invoke.

No other file is created or modified. No database migration, no model, no route, no frontend change.

## Data Flow

1. Developer runs `docker compose up` (OTP serving on `:8080`, as today).
2. Developer runs `php artisan gtfs:map-match`.
3. Command reads `shapes.txt` out of `otp-data/gtfs-jeepney-bus.zip`, calls OTP's own street router once per consecutive point-pair per shape, and rebuilds a denser `shapes.txt` using matched geometry where trustworthy and the original straight segment where not.
4. Command rewrites `gtfs-jeepney-bus.zip` in place with the new `shapes.txt`.
5. Developer restarts OTP (`docker compose up` again, or `--build` if needed) — OTP rebuilds its graph from the now-denser GTFS shapes, exactly as it does today from the original feed.
6. The app's existing `/api/trip-plan` → `drawRoute()` path is unchanged; it just renders better geometry because OTP is serving better geometry.

## Error Handling

- **OTP not reachable at command start** — fail fast, no files touched (see Components step 1).
- **GTFS zip missing/unreadable at command start** — fail fast, no files touched (see Components step 2).
- **OTP request fails mid-run for one pair** (timeout, 500, malformed response) — treated the same as "unmatchable": fallback to the original straight segment for that pair, logged with reason `request_failed`, and the command continues to the next pair rather than aborting the whole run.
- **Zip rewrite safety** — new zip is built fully in a temp location and only swapped into place after success, so a mid-run crash (including on the very last shape) never corrupts the working `gtfs-jeepney-bus.zip`; the original stays intact until the replacement is proven complete.

## Testing

This is a one-time local CLI developer tool operating outside any HTTP request path — no PHPUnit suite, consistent with `otp-data/setup.sh` (a bash script) also having no automated tests in this codebase. Verification is manual:
- Run the command against the real local OTP instance and read the printed summary (shapes processed, matched vs. fallback counts, fallback percentage).
- Rebuild OTP's graph (`docker compose up`) and confirm it still builds successfully from the rewritten zip.
- Visually re-check a previously-zigzag route (e.g., the EDSA corridor identified during diagnosis) in the browser and confirm it now hugs the road.

## Success Criteria

- `php artisan gtfs:map-match` completes without crashing regardless of how many segments fall back, against the real local OTP instance and the real GTFS feed.
- The rewritten `gtfs-jeepney-bus.zip` still builds a valid OTP graph.
- At least one previously-zigzag corridor visibly follows the road afterward.
- The summary log reports the fallback rate clearly enough to judge how much of the feed's matched geometry is trustworthy versus needing manual review.
- Zero changes to any application code (`app/Services/`, `app/Http/Controllers/`, `resources/js/app.js`) — the entire fix is data-layer only.
