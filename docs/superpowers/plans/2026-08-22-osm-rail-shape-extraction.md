# OSM Rail Shape Extraction Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend `gtfs:map-match` so MRT-3, LRT-1, and LRT-2's `shapes.txt` rows are sourced from real, surveyed OSM track geometry instead of the sparse original GTFS shapes — folded into the same command's single run, alongside its existing bus/jeepney CAR-matching.

**Architecture:** A new `extractRailShape()` method shells out to `osmium` (already a documented prerequisite) to pull one route relation's ordered way list and each way's coordinates, then stitches them into one continuous polyline by aligning each way's point direction to the running chain. `handle()`'s existing `$nonRoadShapes` pass-through becomes a per-shape dispatch: shapes in a hardcoded, pre-verified mapping table get OSM-extracted; everything else (PNR, out of scope) keeps today's behavior unchanged.

**Tech Stack:** PHP (`exec`/`shell_exec` to invoke `osmium`), same Artisan command file as `gtfs:map-match`. No new Composer/npm dependency.

## Global Constraints

- All changes are in `app/Console/Commands/MapMatchGtfsShapes.php` — one unified command, no new file, per explicit preference over a second command.
- `RAIL_ENDPOINT_SNAP_TOLERANCE_METERS = 5.0` — exact value from the design spec.
- The verified mapping table (exact values, from the design spec's Verified Mapping section — do not substitute different relation IDs):
  ```
  '880869' => 8000253, // MRT-3
  '882062' => 8000253, // MRT-3
  '882144' => 8000260, // LRT-1
  '882188' => 8000260, // LRT-1
  '880814' => 8000264, // LRT-2
  '882116' => 8000264, // LRT-2
  ```
- PNR (`881953`, `882086`) is explicitly out of scope — not in the mapping table, must keep falling through to "original GTFS points unchanged," exactly as it does today.
- The `--force`/idempotency guard stays scoped to bus/jeepney shapes only (`meanPointSpacingMeters($roadShapes)`) — unchanged by this plan.
- No PHPUnit tests — this is the same one-time local CLI tool `gtfs:map-match` already is; verification is running the actual command against the real environment, per the project's established convention for this command.
- `osmium` is already a documented prerequisite (`otp-data/README.md`, `otp-data/setup.sh`) — treating it as available is not introducing new infrastructure.
- Every task in this plan must leave `otp-data/gtfs-jeepney-bus.zip` either untouched or in a state fully recoverable via `otp-data/setup.sh`'s GTFS-repackaging step (re-downloading the pristine feed) — the same safety discipline the previous `gtfs-shape-map-matching` plan established.

---

## File Structure

- `app/Console/Commands/MapMatchGtfsShapes.php` — **modified only**. New constants, two new private methods (`osmiumIsAvailable`, `extractRailShape`, `stitchWays`, `processRailShapes` — four new methods total), `handle()` and `printSummary()` bodies extended.

---

### Task 1: Preflight checks and the verified rail mapping table

**Files:**
- Modify: `app/Console/Commands/MapMatchGtfsShapes.php`

**Interfaces:**
- Produces (consumed by Tasks 2-3): `OSM_PBF_RELATIVE_PATH`, `RAIL_ENDPOINT_SNAP_TOLERANCE_METERS`, `RAIL_SHAPE_TO_OSM_RELATION` constants; `osmiumIsAvailable(): bool`; `$osmPbfPath` computed in `handle()`.

- [ ] **Step 1: Add the new constants**

Add these after the existing `ALREADY_MATCHED_SPACING_THRESHOLD_METERS` constant (before `CAR_MATCH_QUERY`):

```php
    private const OSM_PBF_RELATIVE_PATH = 'otp-data/metro-manila.osm.pbf';

    private const RAIL_ENDPOINT_SNAP_TOLERANCE_METERS = 5.0;

    /**
     * Both directions of each line reuse the same relation — this GTFS feed
     * models both trip directions with identical shape geometry already, so
     * there's no separate "reverse" shape to source distinctly. Verified by
     * direct extraction (docs/superpowers/specs/2026-08-22-osm-rail-shape-extraction-design.md):
     * each relation's stitched point order already matches the existing GTFS
     * shape's point order, with zero gaps. PNR (881953, 882086) is
     * deliberately absent — no single clean OSM relation covers its extent,
     * so it keeps falling through to the original-points fallback below.
     */
    private const RAIL_SHAPE_TO_OSM_RELATION = [
        '880869' => 8000253, // MRT-3 (Taft Avenue -> North Avenue)
        '882062' => 8000253, // MRT-3 (same physical line; GTFS models both directions identically)
        '882144' => 8000260, // LRT-1 (Dr. Santos -> Fernando Poe Jr.)
        '882188' => 8000260, // LRT-1
        '880814' => 8000264, // LRT-2 (Recto -> Antipolo)
        '882116' => 8000264, // LRT-2
    ];
```

- [ ] **Step 2: Add the `osmiumIsAvailable()` preflight check**

Add this method after `otpIsReachable()`:

```php
    private function osmiumIsAvailable(): bool
    {
        exec('which osmium', $output, $exitCode);

        return $exitCode === 0;
    }
```

- [ ] **Step 3: Wire both new preflight checks into `handle()`**

In `handle()`, insert this block immediately after the existing GTFS-zip-exists check (after the `if (! file_exists($zipPath)) { ... return self::FAILURE; }` block) and before `$extractedDir = $this->extractGtfs($zipPath);`:

```php
        if (! $this->osmiumIsAvailable()) {
            $this->error('osmium-tool is required for rail shape extraction. Install it: brew install osmium-tool (macOS) or apt-get install osmium-tool (Linux).');

            return self::FAILURE;
        }

        $osmPbfPath = base_path(self::OSM_PBF_RELATIVE_PATH);

        if (! file_exists($osmPbfPath)) {
            $this->error("OSM data not found at {$osmPbfPath}");

            return self::FAILURE;
        }

```

- [ ] **Step 4: Verify the success path is unaffected**

Run: `php artisan gtfs:map-match --force` (use `--force` since the feed is likely already matched from prior work — this step only verifies the new checks don't block a normal run, not the matching logic itself)
Expected: proceeds past the two new checks without error, continues into the existing "Matching N bus/jeepney shapes..." flow exactly as before. Exit code depends on whether OTP/data state allows a full run to complete — what matters here is that execution reaches past the new checks, not the final exit code.

- [ ] **Step 5: Verify the osmium-missing failure path**

```bash
sudo mv "$(which osmium)" "$(which osmium).bak"
php artisan gtfs:map-match; echo "exit: $?"
sudo mv "$(which osmium).bak" "$(which osmium)"
```
Expected: prints the osmium-install error message and `exit: 1`, without reaching the GTFS-extraction step. The final `mv` restores osmium — confirm `which osmium` succeeds afterward.

- [ ] **Step 6: Verify the OSM-pbf-missing failure path**

```bash
mv otp-data/metro-manila.osm.pbf otp-data/metro-manila.osm.pbf.bak
php artisan gtfs:map-match; echo "exit: $?"
mv otp-data/metro-manila.osm.pbf.bak otp-data/metro-manila.osm.pbf
```
Expected: prints `OSM data not found at <path>` and `exit: 1`. The final `mv` restores the file — confirm `ls otp-data/metro-manila.osm.pbf` succeeds afterward.

- [ ] **Step 7: Commit**

```bash
git add app/Console/Commands/MapMatchGtfsShapes.php
git commit -m "feat: add osmium/OSM-pbf preflight checks and verified rail shape mapping table"
```

---

### Task 2: Rail shape extraction and way-stitching (standalone, verified before wiring in)

**Files:**
- Modify: `app/Console/Commands/MapMatchGtfsShapes.php`

**Interfaces:**
- Consumes: `RAIL_ENDPOINT_SNAP_TOLERANCE_METERS` (Task 1), `haversineMeters()` (existing).
- Produces (consumed by Task 3): `extractRailShape(string $osmPbfPath, int $relationId): ?array` returning `list<array{lat: float, lon: float}>|null`; `stitchWays(array $orderedWayIds, array $waysById): ?array`.

- [ ] **Step 1: Add `extractRailShape()` and `stitchWays()`**

Add these two methods after `classifyBusShapeIds()`:

```php
    /**
     * @return list<array{lat: float, lon: float}>|null null if the relation
     *         couldn't be resolved into a single connected chain (missing
     *         way, gap beyond tolerance, or the osmium subprocess failed)
     */
    private function extractRailShape(string $osmPbfPath, int $relationId): ?array
    {
        $relationPbf = sys_get_temp_dir().'/gtfs-map-match-rail-'.$relationId.'-'.uniqid().'.osm.pbf';
        $relationGeoJson = $relationPbf.'.geojson';

        exec(sprintf(
            'osmium getid -r %s r%d -o %s --overwrite 2>&1',
            escapeshellarg($osmPbfPath),
            $relationId,
            escapeshellarg($relationPbf),
        ), $getidOutput, $getidExitCode);

        if ($getidExitCode !== 0 || ! file_exists($relationPbf)) {
            @unlink($relationPbf);

            return null;
        }

        $opl = shell_exec(sprintf('osmium cat %s -f opl 2>/dev/null', escapeshellarg($relationPbf)));
        $relationLine = null;

        foreach (explode("\n", (string) $opl) as $line) {
            if (str_starts_with($line, 'r')) {
                $relationLine = $line;

                break;
            }
        }

        if ($relationLine === null) {
            @unlink($relationPbf);

            return null;
        }

        $membersPart = substr($relationLine, strrpos($relationLine, ' ') + 1);
        $wayIds = [];

        foreach (explode(',', $membersPart) as $member) {
            if (str_starts_with($member, 'w') && str_ends_with($member, '@')) {
                $wayIds[] = (int) substr($member, 1, -1);
            }
        }

        if (empty($wayIds)) {
            @unlink($relationPbf);

            return null;
        }

        exec(sprintf(
            'osmium export %s -o %s -f geojson -a id,type --overwrite 2>&1',
            escapeshellarg($relationPbf),
            escapeshellarg($relationGeoJson),
        ), $exportOutput, $exportExitCode);

        @unlink($relationPbf);

        if ($exportExitCode !== 0 || ! file_exists($relationGeoJson)) {
            @unlink($relationGeoJson);

            return null;
        }

        $geoJson = json_decode(file_get_contents($relationGeoJson), true);
        @unlink($relationGeoJson);

        $waysById = [];

        foreach ($geoJson['features'] ?? [] as $feature) {
            if (($feature['geometry']['type'] ?? null) === 'LineString') {
                $wayId = $feature['properties']['@id'] ?? null;

                if ($wayId !== null) {
                    $waysById[(int) $wayId] = array_map(
                        fn ($coord) => ['lat' => $coord[1], 'lon' => $coord[0]],
                        $feature['geometry']['coordinates'],
                    );
                }
            }
        }

        return $this->stitchWays($wayIds, $waysById);
    }

    /**
     * @param  list<int>  $orderedWayIds
     * @param  array<int, list<array{lat: float, lon: float}>>  $waysById
     * @return list<array{lat: float, lon: float}>|null
     */
    private function stitchWays(array $orderedWayIds, array $waysById): ?array
    {
        $chain = [];
        $prevEnd = null;

        foreach ($orderedWayIds as $wayId) {
            $coords = $waysById[$wayId] ?? null;

            if ($coords === null) {
                return null;
            }

            if ($prevEnd === null) {
                array_push($chain, ...$coords);
                $prevEnd = $coords[count($coords) - 1];

                continue;
            }

            $first = $coords[0];
            $last = $coords[count($coords) - 1];
            $distToStart = $this->haversineMeters($prevEnd['lat'], $prevEnd['lon'], $first['lat'], $first['lon']);
            $distToEnd = $this->haversineMeters($prevEnd['lat'], $prevEnd['lon'], $last['lat'], $last['lon']);

            if ($distToStart <= $distToEnd) {
                if ($distToStart > self::RAIL_ENDPOINT_SNAP_TOLERANCE_METERS) {
                    return null;
                }

                array_push($chain, ...array_slice($coords, 1));
                $prevEnd = $last;
            } else {
                if ($distToEnd > self::RAIL_ENDPOINT_SNAP_TOLERANCE_METERS) {
                    return null;
                }

                $reversed = array_reverse($coords);
                array_push($chain, ...array_slice($reversed, 1));
                $prevEnd = $reversed[count($reversed) - 1];
            }
        }

        return $chain;
    }
```

- [ ] **Step 2: Temporarily wire a verification call into `handle()`**

Add this block into `handle()`, immediately after the OSM-pbf-exists check from Task 1 (after `$osmPbfPath = base_path(...)` and its `if (! file_exists($osmPbfPath))` block) and before `$extractedDir = $this->extractGtfs($zipPath);`:

```php
        foreach ([8000253 => 'MRT-3', 8000260 => 'LRT-1', 8000264 => 'LRT-2'] as $testRelationId => $testLineName) {
            $testResult = $this->extractRailShape($osmPbfPath, $testRelationId);
            $testPointCount = $testResult === null ? 'FAILED' : count($testResult);
            $this->info("Test extraction: relation {$testRelationId} ({$testLineName}) -> {$testPointCount} points");
        }

```

- [ ] **Step 3: Run and verify against the pre-verified prototype results**

Run: `php artisan gtfs:map-match --force`
Expected: three lines print:
```
Test extraction: relation 8000253 (MRT-3) -> 163 points
Test extraction: relation 8000260 (LRT-1) -> 313 points
Test extraction: relation 8000264 (LRT-2) -> 169 points
```
These exact counts were independently verified during design (see `docs/superpowers/specs/2026-08-22-osm-rail-shape-extraction-design.md`) — an exact match confirms the PHP port of the stitching logic behaves identically to the Python prototype. If any shows `FAILED` or a different count, do not proceed to Step 4 — investigate first (check `osmium getid`/`osmium cat`/`osmium export` run cleanly by hand against `otp-data/metro-manila.osm.pbf` for that relation ID).

- [ ] **Step 4: Remove the temporary verification block**

Delete the `foreach ([8000253 => 'MRT-3', ...` block added in Step 2 from `handle()`. This was verification scaffolding, not part of the shipped command.

- [ ] **Step 5: Run once more to confirm the removal didn't break anything**

Run: `php artisan gtfs:map-match --force`
Expected: no "Test extraction" lines print; behavior otherwise identical to Task 1's Step 4 verification.

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/MapMatchGtfsShapes.php
git commit -m "feat: add OSM relation extraction and way-stitching for rail shapes"
```

---

### Task 3: Wire rail extraction into the main pipeline and verify end-to-end

**Files:**
- Modify: `app/Console/Commands/MapMatchGtfsShapes.php`

**Interfaces:**
- Consumes: `extractRailShape()` (Task 2), `RAIL_SHAPE_TO_OSM_RELATION` (Task 1).
- Produces: nothing further — this is the plan's last task. `processRailShapes(string $osmPbfPath, array $nonRoadShapes): array`.

- [ ] **Step 1: Add `processRailShapes()`**

Add this method after `stitchWays()`:

```php
    /**
     * @param  array<string, list<array{lat: float, lon: float}>>  $nonRoadShapes
     * @return array{0: array<string, list<array{lat: float, lon: float}>>, 1: array{rail_extracted: int, rail_fallback: int}}
     */
    private function processRailShapes(string $osmPbfPath, array $nonRoadShapes): array
    {
        $processed = [];
        $stats = ['rail_extracted' => 0, 'rail_fallback' => 0];

        foreach ($nonRoadShapes as $shapeId => $originalPoints) {
            $relationId = self::RAIL_SHAPE_TO_OSM_RELATION[$shapeId] ?? null;

            if ($relationId === null) {
                $processed[$shapeId] = $originalPoints;

                continue;
            }

            $extracted = $this->extractRailShape($osmPbfPath, $relationId);

            if ($extracted === null) {
                $this->warn("Rail extraction failed for shape {$shapeId} (relation {$relationId}) — keeping original GTFS points.");
                $processed[$shapeId] = $originalPoints;
                $stats['rail_fallback']++;
            } else {
                $processed[$shapeId] = $extracted;
                $stats['rail_extracted']++;
            }
        }

        return [$processed, $stats];
    }
```

- [ ] **Step 2: Wire it into `handle()`, replacing the direct pass-through**

Replace this line in `handle()`:

```php
        $matchedShapes = $matchedRoadShapes + $nonRoadShapes;
```

with:

```php
        [$processedNonRoadShapes, $railStats] = $this->processRailShapes($osmPbfPath, $nonRoadShapes);

        $stats['rail_extracted'] = $railStats['rail_extracted'];
        $stats['rail_fallback'] = $railStats['rail_fallback'];

        $matchedShapes = $matchedRoadShapes + $processedNonRoadShapes;
```

- [ ] **Step 3: Update `printSummary()` to report rail stats**

Replace the body of `printSummary()`:

```php
    private function printSummary(array $stats): void
    {
        $totalSegments = $stats['segments_matched'] + $stats['segments_fallback'];
        $fallbackPercent = $totalSegments > 0
            ? round($stats['segments_fallback'] / $totalSegments * 100, 1)
            : 0.0;

        $this->info("Shapes processed: {$stats['shapes']}");
        $this->info("Segments matched: {$stats['segments_matched']}");
        $this->info("Segments fell back: {$stats['segments_fallback']} ({$fallbackPercent}%)");
        $this->info("Rail shapes extracted from OSM: {$stats['rail_extracted']}");
        $this->info("Rail shapes kept as original GTFS points: {$stats['rail_fallback']}");

        if (! empty($stats['fallback_log'])) {
            $this->warn('Fallback details:');

            foreach ($stats['fallback_log'] as $entry) {
                $this->line("  shape {$entry['shape_id']} segment {$entry['segment_index']}: {$entry['reason']}");
            }
        }
    }
```

Also update its docblock (the `@param` line immediately above `private function printSummary`) to include the two new keys:

```php
    /**
     * @param  array{shapes: int, segments_matched: int, segments_fallback: int, fallback_log: list<array{shape_id: string, segment_index: int, reason: string}>, rail_extracted: int, rail_fallback: int}  $stats
     */
```

- [ ] **Step 4: Restore a pristine GTFS feed for a clean end-to-end run**

This plan's changes only touch rail shapes, but the bus shapes in the currently-committed `otp-data/gtfs-jeepney-bus.zip` are already matched from prior work — run against a pristine feed so the full command (bus matching + rail extraction together) can be verified from a known-clean starting state:

```bash
OTP_DATA_DIR="$(pwd)/otp-data"
curl -sSL -o /tmp/gtfs-raw.zip https://github.com/sakayph/gtfs/archive/refs/heads/master.zip
rm -rf /tmp/gtfs-extract && mkdir /tmp/gtfs-extract
unzip -q /tmp/gtfs-raw.zip -d /tmp/gtfs-extract
sed -i.bak 's/,20130617,20200630/,20130617,20301231/g' /tmp/gtfs-extract/gtfs-master/calendar.txt
rm -f "$OTP_DATA_DIR/gtfs-jeepney-bus.zip"
(cd /tmp/gtfs-extract/gtfs-master && zip -q -j "$OTP_DATA_DIR/gtfs-jeepney-bus.zip" ./*.txt)
rm -rf /tmp/gtfs-raw.zip /tmp/gtfs-extract
unzip -p "$OTP_DATA_DIR/gtfs-jeepney-bus.zip" shapes.txt | wc -l
```
Expected: the final `unzip -p ... | wc -l` prints `521` (the pristine count).

- [ ] **Step 5: Run the full command end-to-end**

Ensure OTP is running (`docker ps --filter name=sakayph-otp --format "{{.Status}}"`; if not, `docker compose up -d` and wait for it to report ready via a GraphQL poll, not a log grep — per the lesson from the previous plan's Task 1).

Run: `php artisan gtfs:map-match`
Expected output includes (exact bus-matching numbers may vary slightly run-to-run since OTP's CAR routing isn't perfectly deterministic, but the rail numbers must match exactly):
```
Rail shapes extracted from OSM: 6
Rail shapes kept as original GTFS points: 0
```
`processRailShapes()` iterates per-shape, and `RAIL_SHAPE_TO_OSM_RELATION` has 6 entries (MRT-3/LRT-1/LRT-2, 2 shape_ids each), so a clean run with all 3 relations extracting successfully reports `rail_extracted: 6`. `rail_fallback` only increments when an extraction is *attempted and fails* — the 2 PNR shape_ids are never attempted (they're absent from the table entirely), so they don't count toward `rail_fallback` either; expect `0` there in a clean run. PNR's shapes still appear unchanged in the final `shapes.txt`, via `processRailShapes()`'s pass-through branch for shape_ids with no table entry.

- [ ] **Step 6: Verify the rewritten zip and rebuild OTP's graph**

```bash
unzip -l otp-data/gtfs-jeepney-bus.zip
unzip -p otp-data/gtfs-jeepney-bus.zip shapes.txt | wc -l
docker compose restart
```
Expected: `unzip -l` still lists all 9 original GTFS files. Poll `http://localhost:8080/otp/routers/default/index/graphql` with a minimal `{ __typename }` query until it responds successfully (per the established polling pattern — do not grep logs) to confirm the graph rebuilt from the new zip.

- [ ] **Step 7: Confirm the 3 rail lines and PNR via the live API**

With `php artisan serve` running, fetch a route with a RAIL leg for each of the 3 in-scope lines and PNR, decode `legGeometry.points`, and compare point counts against the pre-verified numbers. At minimum, re-check MRT-3 on the original reported route:

```bash
curl -s "http://localhost:8000/api/trip-plan?from_lat=14.657&from_lon=121.0327&to_lat=14.5578&to_lon=121.0244" | python3 -c "
import json, sys
body = json.load(sys.stdin)
for leg in body['options'][0]['legs']:
    if leg['mode'] == 'RAIL':
        print(leg['route'], len(leg['legGeometry']['points']), 'chars encoded')
"
```
Expected: the MRT-3 leg's encoded polyline is visibly longer/denser than before this plan (163 real points vs. the previous 18-point pristine shape). For full confidence, also visually load the app in a browser, search the SM North EDSA → Ayala Avenue route, and zoom into the Camp Aguinaldo/Wack Wack stretch from the originally-reported screenshot — confirm the line now hugs the real corridor instead of bulging away from it.

- [ ] **Step 8: Commit**

```bash
git add app/Console/Commands/MapMatchGtfsShapes.php
git commit -m "feat: wire OSM rail extraction into the main map-matching pipeline"
```

---

## Success Criteria (from design spec)

- All 3 in-scope lines (6 shape_ids) extract with zero fallbacks, matching the pre-verified prototype results (163/313/169 points, 0 gaps each). — Task 2 Step 3, Task 3 Step 5.
- MRT-3's line no longer bulges away from the real EDSA/Ortigas corridor. — Task 3 Step 7.
- PNR's 2 shapes are unaffected. — Task 3 Step 5 (`rail_fallback` stays 0 since PNR isn't in the mapping table at all — it was never attempted, so it can't count as a fallback; its shapes simply pass through `processRailShapes()` unchanged, identical to today).
- Bus/jeepney matching behavior is completely unchanged. — No task modifies `matchPair`, `matchPairViaOtp`, `buildMatchedShapes`, or the CAR-mode query.
- No new runtime dependency beyond `osmium`. — Confirmed throughout; no `composer.json`/`package.json` change in any task.
