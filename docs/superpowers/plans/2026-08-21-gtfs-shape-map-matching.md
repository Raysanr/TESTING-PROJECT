# GTFS Shape Map-Matching Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A one-time local Artisan command that map-matches `otp-data/gtfs-jeepney-bus.zip`'s sparse `shapes.txt` against OTP's own street router, producing dense, road-following shapes with no new infrastructure and no application code changes.

**Architecture:** A single new command class, `App\Console\Commands\MapMatchGtfsShapes`, built up incrementally: extract the GTFS zip, parse `shapes.txt`, match each consecutive point-pair per shape against OTP's CAR-mode `plan` query (falling back to the original straight segment when OTP can't route it or the match looks like an implausible detour), write the denser `shapes.txt` back, and re-zip in place.

**Tech Stack:** Laravel Artisan command, PHP's built-in `ZipArchive` (no new Composer dependency), `Illuminate\Support\Facades\Http` (already used the same way by `TripPlannerService`).

## Global Constraints

- No new infrastructure. The only "matcher" is the OTP instance already started via `docker compose up`, reached through `config('services.otp.url')` — the exact same config key `TripPlannerService` already uses.
- No application code changes anywhere outside this one new file. `TripPlannerService`, `RouteScorer`, `FareEstimator`, `resources/js/app.js` are untouched.
- `DETOUR_RATIO_THRESHOLD = 2.5` — exact value from the design spec, a named class constant.
- GTFS zip path: `otp-data/gtfs-jeepney-bus.zip`, referenced via `base_path('otp-data/gtfs-jeepney-bus.zip')`.
- No new npm/Composer dependency — `ZipArchive` is a built-in PHP extension (confirmed present: `php -m | grep zip` → `zip`).
- Per the approved design spec: this is a one-time local CLI developer tool outside any HTTP request path, in the same category as `otp-data/setup.sh` — **no PHPUnit test suite**. Verification for every task in this plan is running the actual command against the real local OTP instance and the real GTFS feed, and inspecting the real output. Do not add automated tests for this command.
- Every task except the last leaves `otp-data/gtfs-jeepney-bus.zip` untouched (safe to re-run repeatedly while iterating) — only the final task performs the destructive rezip-in-place step, and only after a full successful run.
- Follow existing code style: match `TripPlannerService`'s pattern for the OTP HTTP call (`Http::timeout(...)->post(config('services.otp.url'), [...])`, catching `ConnectionException`), and Laravel's standard Artisan command conventions (`$signature`, `$description`, `handle(): int` returning `self::SUCCESS`/`self::FAILURE`).

---

## File Structure

- `app/Console/Commands/MapMatchGtfsShapes.php` — **new**. The entire feature: one Artisan command, built up task-by-task. Laravel auto-discovers commands placed in `app/Console/Commands/` — no manual registration in `routes/console.php` needed.

---

### Task 1: Command skeleton and preflight checks

**Files:**
- Create: `app/Console/Commands/MapMatchGtfsShapes.php`

**Interfaces:**
- Produces (consumed by Tasks 2-5, which extend this same file): the command class itself, registered automatically as `php artisan gtfs:map-match`; the `GTFS_ZIP_RELATIVE_PATH` constant.

- [ ] **Step 1: Create the command with fail-fast preflight checks**

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class MapMatchGtfsShapes extends Command
{
    protected $signature = 'gtfs:map-match';

    protected $description = "Map-match GTFS shapes.txt against OTP's street router to produce road-following geometry";

    private const GTFS_ZIP_RELATIVE_PATH = 'otp-data/gtfs-jeepney-bus.zip';

    public function handle(): int
    {
        if (! $this->otpIsReachable()) {
            $this->error("OTP isn't running — start it with docker compose up first.");

            return self::FAILURE;
        }

        $zipPath = base_path(self::GTFS_ZIP_RELATIVE_PATH);

        if (! file_exists($zipPath)) {
            $this->error("GTFS zip not found at {$zipPath}");

            return self::FAILURE;
        }

        $this->info('OTP is reachable and the GTFS zip exists.');

        return self::SUCCESS;
    }

    private function otpIsReachable(): bool
    {
        try {
            $response = Http::timeout(5)->post(config('services.otp.url'), [
                'query' => '{ __typename }',
            ]);
        } catch (ConnectionException) {
            return false;
        }

        return $response->successful();
    }
}
```

- [ ] **Step 2: Verify the OTP-down failure path**

Run: `docker compose stop` (from the project root, stops the OTP container), then `php artisan gtfs:map-match; echo "exit: $?"`
Expected: prints `OTP isn't running — start it with docker compose up first.` and `exit: 1`.

- [ ] **Step 3: Verify the missing-zip failure path**

Run: `docker compose up -d` (restart OTP), wait for it to report ready (`docker logs sakayph-otp | grep "Grizzly server running"`, poll every few seconds if not yet present), then:
```bash
mv otp-data/gtfs-jeepney-bus.zip otp-data/gtfs-jeepney-bus.zip.bak
php artisan gtfs:map-match; echo "exit: $?"
mv otp-data/gtfs-jeepney-bus.zip.bak otp-data/gtfs-jeepney-bus.zip
```
Expected: prints `GTFS zip not found at <path>` and `exit: 1`. The final `mv` restores the file — confirm `ls otp-data/gtfs-jeepney-bus.zip` succeeds afterward.

- [ ] **Step 4: Verify the success path**

Run: `php artisan gtfs:map-match; echo "exit: $?"`
Expected: prints `OTP is reachable and the GTFS zip exists.` and `exit: 0`.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/MapMatchGtfsShapes.php
git commit -m "feat: add gtfs:map-match command skeleton with preflight checks"
```

---

### Task 2: GTFS extraction and shapes.txt parsing

**Files:**
- Modify: `app/Console/Commands/MapMatchGtfsShapes.php`

**Interfaces:**
- Consumes: nothing new from Task 1 beyond the existing `handle()` structure.
- Produces (consumed by Task 3-4): `extractGtfs(string $zipPath): string` (returns the temp extraction directory path), `parseShapes(string $extractedDir): array` (returns `array<string, list<array{lat: float, lon: float}>>`, shape_id => ordered points), `cleanup(string $dir): void`.

- [ ] **Step 1: Add extraction, parsing, and cleanup; wire into `handle()`**

Replace the body of `handle()` (keep the two preflight checks as-is) with:

```php
    public function handle(): int
    {
        if (! $this->otpIsReachable()) {
            $this->error("OTP isn't running — start it with docker compose up first.");

            return self::FAILURE;
        }

        $zipPath = base_path(self::GTFS_ZIP_RELATIVE_PATH);

        if (! file_exists($zipPath)) {
            $this->error("GTFS zip not found at {$zipPath}");

            return self::FAILURE;
        }

        $extractedDir = $this->extractGtfs($zipPath);
        $groupedShapes = $this->parseShapes($extractedDir);

        $totalPoints = array_sum(array_map('count', $groupedShapes));

        $this->info('Shapes found: '.count($groupedShapes));
        $this->info("Total points across all shapes: {$totalPoints}");

        $this->cleanup($extractedDir);

        return self::SUCCESS;
    }
```

Add these three new private methods after `otpIsReachable()`:

```php
    private function extractGtfs(string $zipPath): string
    {
        $tempDir = sys_get_temp_dir().'/gtfs-map-match-'.uniqid();
        mkdir($tempDir, recursive: true);

        $zip = new \ZipArchive();
        $zip->open($zipPath);
        $zip->extractTo($tempDir);
        $zip->close();

        return $tempDir;
    }

    /**
     * @return array<string, list<array{lat: float, lon: float}>> shape_id => ordered points
     */
    private function parseShapes(string $extractedDir): array
    {
        $handle = fopen($extractedDir.'/shapes.txt', 'r');
        $header = fgetcsv($handle);
        $idIndex = array_search('shape_id', $header);
        $seqIndex = array_search('shape_pt_sequence', $header);
        $latIndex = array_search('shape_pt_lat', $header);
        $lonIndex = array_search('shape_pt_lon', $header);

        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = [
                'shape_id' => $row[$idIndex],
                'sequence' => (int) $row[$seqIndex],
                'lat' => (float) $row[$latIndex],
                'lon' => (float) $row[$lonIndex],
            ];
        }

        fclose($handle);

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[$row['shape_id']][] = $row;
        }

        foreach ($grouped as $shapeId => $points) {
            usort($points, fn ($a, $b) => $a['sequence'] <=> $b['sequence']);
            $grouped[$shapeId] = array_map(
                fn ($p) => ['lat' => $p['lat'], 'lon' => $p['lon']],
                $points,
            );
        }

        return $grouped;
    }

    private function cleanup(string $extractedDir): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($extractedDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }

        rmdir($extractedDir);
    }
```

- [ ] **Step 2: Verify against the real feed**

Run: `php artisan gtfs:map-match`
Expected: prints a shape count and a total-points count, then exits 0. Cross-check the total-points number against the raw feed: `unzip -p otp-data/gtfs-jeepney-bus.zip shapes.txt | tail -n +2 | wc -l` should print the same number (520, per the header-line count of 521 confirmed earlier minus the header row itself).

Confirm the command left the original zip untouched: `git status --short otp-data/` should show nothing (the file isn't tracked by git per `.gitignore`, but confirm via `md5 otp-data/gtfs-jeepney-bus.zip` before and after — they should match).

- [ ] **Step 3: Commit**

```bash
git add app/Console/Commands/MapMatchGtfsShapes.php
git commit -m "feat: extract and parse GTFS shapes.txt into grouped, ordered points"
```

---

### Task 3: Point-pair matching against OTP

**Files:**
- Modify: `app/Console/Commands/MapMatchGtfsShapes.php`

**Interfaces:**
- Consumes: `config('services.otp.url')` (existing config key, same one `TripPlannerService` uses).
- Produces (consumed by Task 4): `matchPair(array $pointA, array $pointB): array{points: list<array{lat: float, lon: float}>, matched: bool, reason: ?string}`, plus its helpers `matchPairViaOtp`, `decodePolyline`, `haversineMeters`, `pathLengthMeters`. Point arrays throughout are `array{lat: float, lon: float}`.

- [ ] **Step 1: Add the matching constant and helper methods**

Add this constant alongside `GTFS_ZIP_RELATIVE_PATH`:

```php
    private const DETOUR_RATIO_THRESHOLD = 2.5;

    private const CAR_MATCH_QUERY = <<<'GRAPHQL'
        query CarMatch($fromLat: Float!, $fromLon: Float!, $toLat: Float!, $toLon: Float!) {
            plan(
                from: { lat: $fromLat, lon: $fromLon }
                to: { lat: $toLat, lon: $toLon }
                transportModes: [{ mode: CAR }]
            ) {
                itineraries {
                    legs {
                        legGeometry { points }
                    }
                }
            }
        }
        GRAPHQL;
```

Add these methods after `cleanup()`:

```php
    /**
     * @return array{points: list<array{lat: float, lon: float}>, matched: bool, reason: ?string}
     */
    private function matchPair(array $pointA, array $pointB): array
    {
        $otpResult = $this->matchPairViaOtp($pointA['lat'], $pointA['lon'], $pointB['lat'], $pointB['lon']);

        if ($otpResult['points'] === null) {
            return ['points' => [$pointA, $pointB], 'matched' => false, 'reason' => $otpResult['reason']];
        }

        $matched = $otpResult['points'];
        $straightLine = $this->haversineMeters($pointA['lat'], $pointA['lon'], $pointB['lat'], $pointB['lon']);
        $roadLength = $this->pathLengthMeters($matched);

        if ($straightLine > 0 && $roadLength / $straightLine > self::DETOUR_RATIO_THRESHOLD) {
            return ['points' => [$pointA, $pointB], 'matched' => false, 'reason' => 'detour_ratio'];
        }

        return ['points' => $matched, 'matched' => true, 'reason' => null];
    }

    /**
     * @return array{points: list<array{lat: float, lon: float}>|null, reason: ?string} reason is
     *         'request_failed' (OTP unreachable or errored) or 'unmatchable' (OTP found no route)
     *         when points is null; null reason when points is non-null.
     */
    private function matchPairViaOtp(float $lat1, float $lon1, float $lat2, float $lon2): array
    {
        try {
            $response = Http::timeout(10)->post(config('services.otp.url'), [
                'query' => self::CAR_MATCH_QUERY,
                'variables' => [
                    'fromLat' => $lat1, 'fromLon' => $lon1,
                    'toLat' => $lat2, 'toLon' => $lon2,
                ],
            ]);
        } catch (ConnectionException) {
            return ['points' => null, 'reason' => 'request_failed'];
        }

        if ($response->failed()) {
            return ['points' => null, 'reason' => 'request_failed'];
        }

        $itineraries = $response->json('data.plan.itineraries') ?? [];

        if (empty($itineraries)) {
            return ['points' => null, 'reason' => 'unmatchable'];
        }

        $encoded = $itineraries[0]['legs'][0]['legGeometry']['points'] ?? null;

        if ($encoded === null) {
            return ['points' => null, 'reason' => 'unmatchable'];
        }

        return ['points' => $this->decodePolyline($encoded), 'reason' => null];
    }

    /**
     * Google encoded polyline algorithm (precision 5), matching the decoder in
     * resources/js/app.js — OTP's legGeometry.points uses this same encoding.
     *
     * @return list<array{lat: float, lon: float}>
     */
    private function decodePolyline(string $encoded): array
    {
        $points = [];
        $index = 0;
        $lat = 0;
        $lon = 0;
        $length = strlen($encoded);

        while ($index < $length) {
            $shift = 0;
            $result = 0;

            do {
                $byte = ord($encoded[$index++]) - 63;
                $result |= ($byte & 0x1f) << $shift;
                $shift += 5;
            } while ($byte >= 0x20);

            $lat += ($result & 1) ? ~($result >> 1) : ($result >> 1);

            $shift = 0;
            $result = 0;

            do {
                $byte = ord($encoded[$index++]) - 63;
                $result |= ($byte & 0x1f) << $shift;
                $shift += 5;
            } while ($byte >= 0x20);

            $lon += ($result & 1) ? ~($result >> 1) : ($result >> 1);

            $points[] = ['lat' => $lat / 1e5, 'lon' => $lon / 1e5];
        }

        return $points;
    }

    private function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * @param  list<array{lat: float, lon: float}>  $points
     */
    private function pathLengthMeters(array $points): float
    {
        $total = 0.0;

        for ($i = 0; $i < count($points) - 1; $i++) {
            $total += $this->haversineMeters(
                $points[$i]['lat'], $points[$i]['lon'],
                $points[$i + 1]['lat'], $points[$i + 1]['lon'],
            );
        }

        return $total;
    }
```

- [ ] **Step 2: Temporarily wire a single-pair test into `handle()` to verify matching works**

Add this block into `handle()`, right after the `$this->info("Total points across all shapes: {$totalPoints}");` line and before `$this->cleanup($extractedDir);`:

```php
        $firstShape = array_key_first($groupedShapes);
        $firstShapePoints = $groupedShapes[$firstShape];
        $testResult = $this->matchPair($firstShapePoints[0], $firstShapePoints[1]);

        $this->info("Test match on shape {$firstShape}, first pair: matched=".($testResult['matched'] ? 'yes' : 'no')
            .', reason='.($testResult['reason'] ?? 'n/a')
            .', points='.count($testResult['points']));
```

- [ ] **Step 3: Run and verify**

Run: `php artisan gtfs:map-match`
Expected: the new line prints, e.g. `Test match on shape 880814, first pair: matched=yes, reason=n/a, points=12` (exact shape id and point count will vary — what matters is `matched=yes` with `points` greater than 2 for at least a typical pair, since OTP's road-snapped path between two nearby points almost always has more than the original 2 points). If every local test pair reports `matched=no`, re-check OTP is serving correctly (`curl` the CAR-mode query manually, as verified during design) before proceeding.

- [ ] **Step 4: Remove the temporary test block**

Delete the 5-line block added in Step 2 (the `$firstShape = ...` through the `$this->info("Test match on shape...")` lines) from `handle()`, restoring it to just the extraction/parsing/counts/cleanup flow from Task 2. This was verification scaffolding, not part of the shipped command.

- [ ] **Step 5: Run once more to confirm the removal didn't break anything**

Run: `php artisan gtfs:map-match`
Expected: same output as Task 2's Step 2 (shape count, total points, exit 0) — no test-match line.

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/MapMatchGtfsShapes.php
git commit -m "feat: add OTP-backed point-pair map-matching with unmatchable/detour-ratio fallback"
```

---

### Task 4: Full shape assembly and shapes.txt rewrite (non-destructive)

**Files:**
- Modify: `app/Console/Commands/MapMatchGtfsShapes.php`

**Interfaces:**
- Consumes: `matchPair` (Task 3), `parseShapes`'s output shape (Task 2).
- Produces (consumed by Task 5): `buildMatchedShapes(array $groupedShapes): array{0: array<string, list<array{lat: float, lon: float}>>, 1: array{shapes: int, segments_matched: int, segments_fallback: int, fallback_log: list<array{shape_id: string, segment_index: int, reason: string}>}}`, `writeShapesCsv(string $dir, array $matchedShapes): void`, `printSummary(array $stats): void`.

- [ ] **Step 1: Add shape assembly, CSV writing, and summary printing**

Add these methods after `pathLengthMeters()`:

```php
    /**
     * @param  array<string, list<array{lat: float, lon: float}>>  $groupedShapes
     * @return array{0: array<string, list<array{lat: float, lon: float}>>, 1: array{shapes: int, segments_matched: int, segments_fallback: int, fallback_log: list<array{shape_id: string, segment_index: int, reason: string}>}}
     */
    private function buildMatchedShapes(array $groupedShapes): array
    {
        $matchedShapes = [];
        $stats = ['shapes' => 0, 'segments_matched' => 0, 'segments_fallback' => 0, 'fallback_log' => []];

        foreach ($groupedShapes as $shapeId => $points) {
            $stats['shapes']++;
            $newPoints = [$points[0]];

            for ($i = 0; $i < count($points) - 1; $i++) {
                $result = $this->matchPair($points[$i], $points[$i + 1]);

                $segmentPoints = array_slice($result['points'], 1);
                array_push($newPoints, ...$segmentPoints);

                if ($result['matched']) {
                    $stats['segments_matched']++;
                } else {
                    $stats['segments_fallback']++;
                    $stats['fallback_log'][] = [
                        'shape_id' => $shapeId,
                        'segment_index' => $i,
                        'reason' => $result['reason'],
                    ];
                }
            }

            $matchedShapes[$shapeId] = $newPoints;
        }

        return [$matchedShapes, $stats];
    }

    /**
     * @param  array<string, list<array{lat: float, lon: float}>>  $matchedShapes
     */
    private function writeShapesCsv(string $extractedDir, array $matchedShapes): void
    {
        $handle = fopen($extractedDir.'/shapes.txt', 'w');
        fputcsv($handle, ['shape_id', 'shape_pt_sequence', 'shape_pt_lat', 'shape_pt_lon']);

        foreach ($matchedShapes as $shapeId => $points) {
            foreach ($points as $sequence => $point) {
                fputcsv($handle, [$shapeId, $sequence, $point['lat'], $point['lon']]);
            }
        }

        fclose($handle);
    }

    /**
     * @param  array{shapes: int, segments_matched: int, segments_fallback: int, fallback_log: list<array{shape_id: string, segment_index: int, reason: string}>}  $stats
     */
    private function printSummary(array $stats): void
    {
        $totalSegments = $stats['segments_matched'] + $stats['segments_fallback'];
        $fallbackPercent = $totalSegments > 0
            ? round($stats['segments_fallback'] / $totalSegments * 100, 1)
            : 0.0;

        $this->info("Shapes processed: {$stats['shapes']}");
        $this->info("Segments matched: {$stats['segments_matched']}");
        $this->info("Segments fell back: {$stats['segments_fallback']} ({$fallbackPercent}%)");

        if (! empty($stats['fallback_log'])) {
            $this->warn('Fallback details:');

            foreach ($stats['fallback_log'] as $entry) {
                $this->line("  shape {$entry['shape_id']} segment {$entry['segment_index']}: {$entry['reason']}");
            }
        }
    }
```

- [ ] **Step 2: Wire assembly and CSV writing into `handle()`, still non-destructively**

Replace the body of `handle()` with:

```php
    public function handle(): int
    {
        if (! $this->otpIsReachable()) {
            $this->error("OTP isn't running — start it with docker compose up first.");

            return self::FAILURE;
        }

        $zipPath = base_path(self::GTFS_ZIP_RELATIVE_PATH);

        if (! file_exists($zipPath)) {
            $this->error("GTFS zip not found at {$zipPath}");

            return self::FAILURE;
        }

        $extractedDir = $this->extractGtfs($zipPath);
        $groupedShapes = $this->parseShapes($extractedDir);

        $this->info('Matching '.count($groupedShapes).' shapes against OTP...');

        [$matchedShapes, $stats] = $this->buildMatchedShapes($groupedShapes);

        $this->writeShapesCsv($extractedDir, $matchedShapes);

        $this->printSummary($stats);

        $this->info("Matched shapes.txt written to: {$extractedDir}/shapes.txt (not yet applied — original zip untouched)");

        return self::SUCCESS;
    }
```

Note this task deliberately does NOT call `cleanup($extractedDir)` yet — the temp directory is left in place so its `shapes.txt` can be inspected in Step 3, and Task 5 will take over the directory's lifecycle once the rezip step exists.

- [ ] **Step 3: Run against the real feed and inspect the result**

Run: `php artisan gtfs:map-match`

This will take longer than previous tasks — it's now making one OTP call per consecutive point-pair across the whole feed (~500 calls). Expected: prints the shape/segment/fallback summary, plus the printed temp directory path.

Then inspect the output directly:
```bash
wc -l <printed-temp-dir>/shapes.txt
```
Expected: significantly more lines than the original 521 (the whole point of matching — denser geometry). Confirm the original is still untouched: `unzip -p otp-data/gtfs-jeepney-bus.zip shapes.txt | wc -l` should still print 521.

- [ ] **Step 4: Commit**

```bash
git add app/Console/Commands/MapMatchGtfsShapes.php
git commit -m "feat: assemble matched shapes and write a denser shapes.txt (non-destructive)"
```

---

### Task 5: Rezip in place and finalize

**Files:**
- Modify: `app/Console/Commands/MapMatchGtfsShapes.php`

**Interfaces:**
- Consumes: everything from Tasks 1-4.
- Produces: nothing further — this is the plan's last task. `rezip(string $extractedDir, string $originalZipPath): void`.

- [ ] **Step 1: Add the rezip method**

Add this method after `writeShapesCsv()`:

```php
    private function rezip(string $extractedDir, string $originalZipPath): void
    {
        $tempZipPath = $originalZipPath.'.tmp';

        if (file_exists($tempZipPath)) {
            unlink($tempZipPath);
        }

        $zip = new \ZipArchive();
        $zip->open($tempZipPath, \ZipArchive::CREATE);

        foreach (scandir($extractedDir) as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $zip->addFile($extractedDir.'/'.$file, $file);
        }

        $zip->close();

        rename($tempZipPath, $originalZipPath);
    }
```

- [ ] **Step 2: Wire the rezip and cleanup into `handle()`**

Replace the body of `handle()` with:

```php
    public function handle(): int
    {
        if (! $this->otpIsReachable()) {
            $this->error("OTP isn't running — start it with docker compose up first.");

            return self::FAILURE;
        }

        $zipPath = base_path(self::GTFS_ZIP_RELATIVE_PATH);

        if (! file_exists($zipPath)) {
            $this->error("GTFS zip not found at {$zipPath}");

            return self::FAILURE;
        }

        $extractedDir = $this->extractGtfs($zipPath);
        $groupedShapes = $this->parseShapes($extractedDir);

        $this->info('Matching '.count($groupedShapes).' shapes against OTP...');

        [$matchedShapes, $stats] = $this->buildMatchedShapes($groupedShapes);

        $this->writeShapesCsv($extractedDir, $matchedShapes);
        $this->rezip($extractedDir, $zipPath);
        $this->cleanup($extractedDir);

        $this->printSummary($stats);

        $this->info('otp-data/gtfs-jeepney-bus.zip updated. Restart OTP (docker compose up) to rebuild the graph with the new shapes.');

        return self::SUCCESS;
    }
```

- [ ] **Step 3: Back up the current zip before the first destructive run**

```bash
cp otp-data/gtfs-jeepney-bus.zip /tmp/gtfs-jeepney-bus.zip.pre-match-backup
```

This is a manual safety net for this one verification run, not part of the shipped command.

- [ ] **Step 4: Run the full end-to-end command**

Run: `php artisan gtfs:map-match`
Expected: same summary output as Task 4, plus the final "updated... restart OTP" message. Exit 0.

Verify the zip changed and is still a valid archive:
```bash
unzip -l otp-data/gtfs-jeepney-bus.zip
unzip -p otp-data/gtfs-jeepney-bus.zip shapes.txt | wc -l
```
Expected: `unzip -l` lists all the original GTFS files (agency.txt, calendar.txt, feed_info.txt, frequencies.txt, routes.txt, shapes.txt, stop_times.txt, stops.txt, trips.txt) — same file set as before, just `shapes.txt` changed — and the `shapes.txt` line count is now much larger than 521.

- [ ] **Step 5: Rebuild OTP's graph from the matched feed**

Run: `docker compose up` (foreground, or `docker compose up -d` then `docker logs -f sakayph-otp`)
Expected: the graph builds successfully and logs `Grizzly server running.`, the same success signal used during original setup — confirming the rewritten GTFS zip is still valid input OTP can build from.

- [ ] **Step 6: Visually confirm the fix**

With OTP and `php artisan serve`/`npm run dev` running, load the app in a browser, search the SM North EDSA → Ayala Avenue route (or any route producing a transit leg along a previously-zigzag corridor), and confirm the drawn line now hugs the road instead of cutting through blocks.

If satisfied, remove the manual backup: `rm /tmp/gtfs-jeepney-bus.zip.pre-match-backup`. If not satisfied, restore it: `cp /tmp/gtfs-jeepney-bus.zip.pre-match-backup otp-data/gtfs-jeepney-bus.zip` and investigate the fallback log's reasons before re-running.

- [ ] **Step 7: Commit**

```bash
git add app/Console/Commands/MapMatchGtfsShapes.php
git commit -m "feat: rezip matched shapes in place, completing gtfs:map-match"
```

---

## Success Criteria (from design spec)

- `php artisan gtfs:map-match` completes without crashing regardless of how many segments fall back. — Verified across all tasks' runs; Task 3's fallback path and Task 1's failure paths are exercised directly.
- The rewritten `gtfs-jeepney-bus.zip` still builds a valid OTP graph. — Task 5, Step 5.
- At least one previously-zigzag corridor visibly follows the road afterward. — Task 5, Step 6.
- The summary log reports the fallback rate clearly. — `printSummary()`, Task 4.
- Zero changes to any application code outside this one new file. — No task in this plan touches `app/Services/`, `app/Http/Controllers/`, or `resources/js/app.js`.
