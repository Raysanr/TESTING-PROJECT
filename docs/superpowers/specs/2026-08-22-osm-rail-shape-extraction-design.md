# OSM Rail Shape Extraction — Design

## Context

`gtfs:map-match` (shipped, see `docs/superpowers/specs/2026-08-21-gtfs-shape-map-matching-design.md`) fixed zigzag rendering for bus/jeepney legs by CAR-mode matching them against OTP's street router, and deliberately excludes rail shapes from that process — CAR-mode routing is categorically wrong for trains, which run on dedicated track, not roads.

That left rail geometry exactly as sparse as the source GTFS feed provides it (confirmed: MRT-3's shape is 18 points over 12.3km, ~684m average spacing), which a user spotted as a visibly wrong line on the map — it cuts through blocks (e.g. Camp Aguinaldo/Wack Wack) instead of tracing the real elevated corridor along EDSA/Ortigas Avenue.

Investigation found real, dense, human-surveyed track geometry already sitting in `otp-data/metro-manila.osm.pbf` — OSM's own `railway=light_rail`/`subway` ways, organized into maintained route relations with ways already listed in correct travel order. A working extraction was prototyped and directly verified against the specific relation this design uses (`8000253`, Taft→North Avenue): 163 points for MRT-3 vs. the current 18, zero gaps, 16,921m — matching the line's real ~16.9km length almost exactly, with start/end coordinates landing within meters of the existing GTFS shape's own endpoints.

## Goal

Replace the sparse GTFS rail shapes for MRT-3, LRT-1, and LRT-2 with real track geometry extracted from OSM, folded into the same `gtfs:map-match` command (per explicit preference — one unified pipeline, not a second command) — so a single run of `php artisan gtfs:map-match` produces road-matched bus geometry *and* OSM-sourced rail geometry.

## Scope

**In scope:**
- MRT-3, LRT-1, LRT-2 (6 of the 8 rail shape_ids) — each has a confirmed, unambiguous 1:1 OSM route relation with zero-gap stitching, verified by direct extraction (not assumed).
- Folding the extraction into `gtfs:map-match`'s existing `handle()` flow, alongside the existing bus/jeepney CAR-matching path.
- New preflight check for `osmium` availability (already a documented prerequisite via `otp-data/setup.sh`/README — not new infrastructure).

**Explicitly out of scope:**
- **PNR** (`881953`, `882086`) — investigated, does not have a single clean OSM route relation covering our GTFS shape's actual extent. OSM splits PNR into "Metro North Commuter Line" (Tutuban→Governor Pascual), a separate "Shuttle Service Line" (Governor Pascual→FTI), and an unrelated, much longer "South Main Line" (Manila→Legazpi, intercity, hundreds of km). Figuring out which combination (if any) matches our GTFS shape's real southern endpoint (~14.33°N) is separate investigative work. PNR's two shapes are simply absent from the mapping table this design introduces, so they fall through to the existing "keep original GTFS points" fallback — identical to today's behavior, not a regression.
- Any change to the bus/jeepney CAR-matching path — untouched by this work.
- Any application code outside `app/Console/Commands/MapMatchGtfsShapes.php`.

## Verified Mapping

Both directions of each line reuse the same relation — this GTFS feed models both trip directions with byte-identical shape geometry already (confirmed: `880869` and `882062`'s points are identical in the current feed), so there's no separate "reverse" shape to source distinctly. Each chosen relation's stitched point order already matches the existing GTFS shape's point order (south→north or west→east, matching what's already in `shapes.txt`), so no additional reversal is needed at the shape-assignment level (only within-line way-to-way alignment, handled by the stitching algorithm itself).

| GTFS shape_id(s) | Line | OSM relation | Verified via direct extraction |
|---|---|---|---|
| `880869`, `882062` | MRT-3 | `8000253` ("Taft Avenue → North Avenue") | Stitched cleanly, 163 pts, 0 gaps, 16,921m |
| `882144`, `882188` | LRT-1 | `8000260` ("Dr. Santos → Fernando Poe Jr.") | Stitched cleanly, 313 pts, 0 gaps |
| `880814`, `882116` | LRT-2 | `8000264` ("Recto → Antipolo") | Stitched cleanly, 169 pts, 0 gaps |

## Architecture

```
otp-data/metro-manila.osm.pbf
        |
        | for each of the 3 mapped lines:
        v
osmium getid -r <pbf> r<relation_id> -o <temp>.osm.pbf
        |  (pulls the relation + all referenced ways/nodes)
        v
osmium cat <temp>.osm.pbf -f opl
        |  (parse the relation line: ordered member list,
        |   filter to way members with EMPTY role —
        |   excludes platform/stop members, keeps only track ways)
        v
osmium export <temp>.osm.pbf -f geojson -a id,type
        |  (get each member way's coordinate list, keyed by @id)
        v
Walk the ordered way-id list, stitching:
  for each way, compare its start/end distance to the running
  chain's current end point; append in whichever orientation
  connects (flip the way's points if its end is closer than
  its start) — the SEQUENCE is already correct from the
  relation; only within-way DIRECTION needs resolving.
        |
  gap > 5m between consecutive ways? -> abort this shape,
  fall back to its original GTFS points, log a warning
        |
        v
[shape's new dense point list] -> merged into $matchedShapes
        alongside the existing CAR-matched bus shapes and any
        untouched (PNR) rail shapes -> written to shapes.txt,
        rezipped in place (existing mechanism, unchanged)
```

## Components

All changes are in `app/Console/Commands/MapMatchGtfsShapes.php` (no new file — one unified command, per explicit preference).

**New constants:**
- `OSM_PBF_RELATIVE_PATH = 'otp-data/metro-manila.osm.pbf'`
- `RAIL_ENDPOINT_SNAP_TOLERANCE_METERS = 5.0`
- `RAIL_SHAPE_TO_OSM_RELATION` — the 6-entry map from the Verified Mapping table above (`shape_id => relation_id`).

**New method `osmiumIsAvailable(): bool`** — preflight check (e.g. checking the binary resolves via the shell), alongside the existing `otpIsReachable()`. Fails fast with the same install instructions the README already gives (`brew install osmium-tool`) if missing, before touching anything.

**New method `extractRailShape(string $osmPbfPath, int $relationId): ?array`** — the core new logic:
1. Shell `osmium getid -r` to extract the relation + referenced ways/nodes to a temp `.osm.pbf`.
2. Shell `osmium cat -f opl` on that temp file, parse the single relation line's member list, keep only `w<id>@` entries (empty role — excludes `@platform`, `@stop`, etc.).
3. Shell `osmium export -f geojson -a id,type` on the same temp file, build a `way_id => list<[lon,lat]>` map from the resulting `LineString` features.
4. Walk the ordered way-id list from step 2, looking up each way's coordinates from step 3, stitching into one chain (flip-if-closer logic, exactly as prototyped).
5. Return the assembled `list<array{lat,lon}>`, or `null` if any way is missing from the export, or any consecutive gap exceeds `RAIL_ENDPOINT_SNAP_TOLERANCE_METERS`.
6. Clean up its own temp files regardless of outcome.

**Modified `handle()`:** the existing split into `$roadShapes`/`$nonRoadShapes` stays. For each `$nonRoadShapes` entry, if its shape_id is a key in `RAIL_SHAPE_TO_OSM_RELATION`, call `extractRailShape()` and use the result if non-null; otherwise (not in the table, or extraction returned `null`) keep the original points unchanged, as today. Stats now also track `rail_extracted`/`rail_fallback` counts, printed in the existing summary alongside the bus stats.

The `--force`/idempotency guard is unaffected — it stays scoped to `$roadShapes` (bus/jeepney) only, since OSM extraction is fully deterministic and always produces the same result regardless of the feed's current bus-matching state; re-running it is always safe.

## Error Handling

- **`osmium` not installed** — new preflight check, fails fast with install instructions, before any file is touched.
- **`metro-manila.osm.pbf` missing** — same fail-fast pattern as the existing GTFS-zip-missing check.
- **A relation ID doesn't resolve** (OSM data changed since this table was verified) — `extractRailShape()` returns `null` for that shape; falls back to original GTFS points, logged as a warning. Command still completes successfully.
- **A stitching gap exceeds tolerance** — same fallback + warning, not fatal.
- **`osmium` subprocess itself fails** (corrupt PBF, disk issue) — treated identically to a missing relation.

None of these error paths are fatal to the overall command — they only ever degrade one shape back to its pre-existing (already-shipped, already-safe) fallback behavior.

## Testing

Same convention as the rest of `gtfs:map-match` — no PHPUnit suite (one-time local CLI tool). Verification: run the command, check the summary reports 3 lines extracted with 0 fallbacks (matching the pre-verified zero-gap results above), rebuild OTP's graph, and visually confirm MRT-3/LRT-1/LRT-2 now trace their real alignment — specifically re-checking the Camp Aguinaldo/Wack Wack stretch from the reported screenshot.

## Success Criteria

- All 3 in-scope lines (6 shape_ids) extract with zero fallbacks, matching this design's pre-verified prototype results.
- MRT-3's line no longer bulges away from the real EDSA/Ortigas corridor — the originally reported bug is visually gone.
- PNR's 2 shapes are unaffected (still using their original GTFS points, exactly as they are today — not worse, just not yet improved).
- Bus/jeepney matching behavior is completely unchanged.
- No new runtime dependency beyond `osmium` (already a documented prerequisite for this project).
