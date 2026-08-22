# Group D: Places & Landmarks Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** WALK-leg instructions and the itinerary's first pickup point reference a nearby real OSM landmark (pharmacy, fast food, place of worship, supermarket, or transport hub) within 100m, instead of only a raw street/intersection name.

**Architecture:** A new `osm:extract-landmarks` Artisan command (mirrors `gtfs:map-match`'s established `osmium`-shelling pattern) populates a `landmarks` table from the already-present `otp-data/metro-manila.osm.pbf`. A new `LandmarkLookupService` does a bounding-box-prefiltered haversine nearest-neighbor lookup against that table. `TripPlannerService`'s GraphQL query gains `lat`/`lon` on leg endpoints, and `RouteScorer` uses the lookup service to enrich its existing instruction lines.

**Tech Stack:** Laravel Artisan command, `osmium` (already a documented prerequisite), Eloquent + SQLite (no spatial extension), PHPUnit (this feature's DB-backed services ARE unit-testable, unlike the OSM-shelling CLI commands in this codebase).

## Global Constraints

- No live Overpass API calls — extraction is offline-only, from `otp-data/metro-manila.osm.pbf`, via `osmium` (already required by `otp-data/setup.sh`).
- No new Composer/npm dependency, no PostGIS/Spatialite — nearest-neighbor lookup is a SQL bounding-box pre-filter (indexed `lat`/`lon` columns) + exact haversine distance computed in PHP on the filtered set.
- `MAX_DISTANCE_METERS = 100.0`, `BOUNDING_BOX_DEGREES = 0.0015` — exact values from the design spec, named class constants.
- 7 POI tag combinations, exact: `highway=bus_stop`, `amenity=taxi`, `railway=station`, `amenity=fast_food`, `amenity=pharmacy`, `shop=supermarket`, `amenity=place_of_worship`.
- TODA/jeepney flagging-zone modeling and Group C wait-penalty scoring are explicitly out of scope — do not implement them in this plan.
- `osm:extract-landmarks` follows the same no-PHPUnit-suite convention as `gtfs:map-match` (one-time/developer-run CLI tool, manual verification). `LandmarkLookupService` and `RouteScorer`'s enrichment logic ARE unit-testable (pure PHP + DB reads, no subprocess/OTP calls) and MUST have PHPUnit coverage, matching `FareEstimatorTest`'s pattern.
- Follow existing code style: Eloquent models with `$fillable`/`$casts` (see `app/Models/Fare.php`), Artisan commands with the preflight-check-then-work structure (see `app/Console/Commands/MapMatchGtfsShapes.php`), constructor-promoted-property DI in services/controllers (see `app/Http/Controllers/TripPlanController.php`).

---

## File Structure

- `database/migrations/2026_08_22_150000_create_landmarks_table.php` — **new**.
- `app/Models/Landmark.php` — **new**. Plain Eloquent model.
- `app/Console/Commands/ExtractOsmLandmarks.php` — **new**. `php artisan osm:extract-landmarks`.
- `app/Services/LandmarkLookupService.php` — **new**. Nearest-landmark lookup.
- `app/Services/TripPlannerService.php` — **modified**. `from`/`to` GraphQL selections gain `lat`/`lon`.
- `app/Services/RouteScorer.php` — **modified**. Constructor-injects `LandmarkLookupService`; `instructions()` enriches WALK legs and the first leg.
- `tests/Feature/LandmarkLookupServiceTest.php` — **new**.
- `tests/Feature/RouteScorerTest.php` — **new** (moved from `tests/Unit/RouteScorerTest.php`, which is deleted — see Task 4).
- `tests/Feature/TripPlannerServiceTest.php` — **modified**. One new assertion for the extended query.

---

### Task 1: `landmarks` table and OSM extraction command

**Files:**
- Create: `database/migrations/2026_08_22_150000_create_landmarks_table.php`
- Create: `app/Models/Landmark.php`
- Create: `app/Console/Commands/ExtractOsmLandmarks.php`

**Interfaces:**
- Produces (consumed by Task 2): `Landmark` Eloquent model with `$fillable = ['osm_node_id', 'name', 'lat', 'lon', 'poi_type']`, `$casts = ['lat' => 'float', 'lon' => 'float']`, backed by a `landmarks` table (`id`, `osm_node_id` nullable unique, `name`, `lat` decimal(10,7), `lon` decimal(10,7), `poi_type` string, timestamps, indexed on `['lat', 'lon']`).

- [ ] **Step 1: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landmarks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('osm_node_id')->nullable()->unique();
            $table->string('name');
            $table->decimal('lat', 10, 7);
            $table->decimal('lon', 10, 7);
            $table->string('poi_type');
            $table->timestamps();
            $table->index(['lat', 'lon']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landmarks');
    }
};
```

- [ ] **Step 2: Create the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Landmark extends Model
{
    protected $fillable = ['osm_node_id', 'name', 'lat', 'lon', 'poi_type'];

    protected $casts = [
        'lat' => 'float',
        'lon' => 'float',
    ];
}
```

- [ ] **Step 3: Run the migration and verify the table**

Run: `php artisan migrate`
Expected: `2026_08_22_150000_create_landmarks_table` reports `DONE`.

- [ ] **Step 4: Create the extraction command**

```php
<?php

namespace App\Console\Commands;

use App\Models\Landmark;
use Illuminate\Console\Command;

class ExtractOsmLandmarks extends Command
{
    protected $signature = 'osm:extract-landmarks';

    protected $description = 'Extract named POIs (transport hubs, pharmacies, fast food, supermarkets, places of worship) from the local OSM data into the landmarks table';

    private const OSM_PBF_RELATIVE_PATH = 'otp-data/metro-manila.osm.pbf';

    private const POI_TAG_FILTERS = [
        'highway=bus_stop' => 'bus_stop',
        'amenity=taxi' => 'taxi',
        'railway=station' => 'station',
        'amenity=fast_food' => 'fast_food',
        'amenity=pharmacy' => 'pharmacy',
        'shop=supermarket' => 'supermarket',
        'amenity=place_of_worship' => 'place_of_worship',
    ];

    public function handle(): int
    {
        exec('command -v osmium', $output, $exitCode);

        if ($exitCode !== 0) {
            $this->error('osmium-tool is required. Install it: brew install osmium-tool (macOS) or apt-get install osmium-tool (Linux).');

            return self::FAILURE;
        }

        $osmPbfPath = base_path(self::OSM_PBF_RELATIVE_PATH);

        if (! file_exists($osmPbfPath)) {
            $this->error("OSM data not found at {$osmPbfPath}");

            return self::FAILURE;
        }

        $filteredPbf = sys_get_temp_dir().'/osm-extract-landmarks-'.uniqid().'.osm.pbf';
        $geoJsonPath = $filteredPbf.'.geojson';

        $tagArgs = implode(' ', array_map('escapeshellarg', array_keys(self::POI_TAG_FILTERS)));

        exec(sprintf(
            'osmium tags-filter %s %s -o %s --overwrite 2>&1',
            escapeshellarg($osmPbfPath),
            $tagArgs,
            escapeshellarg($filteredPbf),
        ), $filterOutput, $filterExitCode);

        if ($filterExitCode !== 0 || ! file_exists($filteredPbf)) {
            $this->error('Failed to filter OSM data for landmark tags.');
            @unlink($filteredPbf);

            return self::FAILURE;
        }

        exec(sprintf(
            'osmium export %s -o %s -f geojson -a id,type --overwrite 2>&1',
            escapeshellarg($filteredPbf),
            escapeshellarg($geoJsonPath),
        ), $exportOutput, $exportExitCode);

        @unlink($filteredPbf);

        if ($exportExitCode !== 0 || ! file_exists($geoJsonPath)) {
            $this->error('Failed to export filtered OSM data to GeoJSON.');
            @unlink($geoJsonPath);

            return self::FAILURE;
        }

        $geoJson = json_decode(file_get_contents($geoJsonPath), true);
        @unlink($geoJsonPath);

        $countsByType = array_fill_keys(array_values(self::POI_TAG_FILTERS), 0);
        $skippedUnnamed = 0;

        foreach ($geoJson['features'] ?? [] as $feature) {
            if (($feature['geometry']['type'] ?? null) !== 'Point') {
                continue;
            }

            $properties = $feature['properties'] ?? [];
            $name = $properties['name'] ?? null;

            if ($name === null) {
                $skippedUnnamed++;

                continue;
            }

            $poiType = $this->classifyPoiType($properties);

            if ($poiType === null) {
                continue;
            }

            $osmId = $properties['@id'] ?? null;
            $coordinates = $feature['geometry']['coordinates'];

            Landmark::updateOrCreate(
                ['osm_node_id' => $osmId],
                ['name' => $name, 'lat' => $coordinates[1], 'lon' => $coordinates[0], 'poi_type' => $poiType],
            );

            $countsByType[$poiType]++;
        }

        $this->info('Landmarks extracted:');

        foreach ($countsByType as $type => $count) {
            $this->info("  {$type}: {$count}");
        }

        $this->info('Skipped (unnamed): '.$skippedUnnamed);
        $this->info('Total upserted: '.array_sum($countsByType));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function classifyPoiType(array $properties): ?string
    {
        foreach (self::POI_TAG_FILTERS as $tag => $poiType) {
            [$key, $value] = explode('=', $tag);

            if (($properties[$key] ?? null) === $value) {
                return $poiType;
            }
        }

        return null;
    }
}
```

- [ ] **Step 5: Run against the real OSM data and inspect the result**

Run: `php artisan osm:extract-landmarks`
Expected: prints a per-`poi_type` count breakdown, a skipped-unnamed count, and a total. All 7 `poi_type` values should have a non-zero count (a real Metro Manila extract has all of these).

Then inspect the table directly:
```bash
php artisan tinker --execute="echo App\Models\Landmark::count();"
php artisan tinker --execute="App\Models\Landmark::inRandomOrder()->limit(5)->get(['name','poi_type','lat','lon'])->each(fn(\$l) => print(\$l->poi_type.': '.\$l->name.' ('.\$l->lat.', '.\$l->lon.')'.PHP_EOL));"
```
Expected: the count matches the command's printed total; the 5 random rows show plausible Metro Manila landmark names with sane coordinates (roughly `14.2`-`14.9` lat, `120.8`-`121.2` lon, matching the bbox `otp-data/README.md` documents).

- [ ] **Step 6: Verify re-running is idempotent**

Run: `php artisan osm:extract-landmarks` a second time.
Expected: same summary counts as Step 5 (not doubled) — confirm via `php artisan tinker --execute="echo App\Models\Landmark::count();"` that the total row count is unchanged from Step 5, proving `updateOrCreate` correctly upserts by `osm_node_id` rather than duplicating.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_08_22_150000_create_landmarks_table.php app/Models/Landmark.php app/Console/Commands/ExtractOsmLandmarks.php
git commit -m "feat: add landmarks table and OSM POI extraction command"
```

---

### Task 2: `LandmarkLookupService`

**Files:**
- Create: `app/Services/LandmarkLookupService.php`
- Create: `tests/Feature/LandmarkLookupServiceTest.php`

**Interfaces:**
- Consumes: `Landmark` model (Task 1).
- Produces (consumed by Task 4): `LandmarkLookupService::nearest(float $lat, float $lon): ?array` — returns `['name' => string, 'poi_type' => string, 'distance' => float]` or `null`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature;

use App\Models\Landmark;
use App\Services\LandmarkLookupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandmarkLookupServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_the_nearest_landmark_within_range(): void
    {
        // ~15m away
        Landmark::create(['name' => 'Near Pharmacy', 'lat' => 14.6571, 'lon' => 121.0328, 'poi_type' => 'pharmacy']);
        // ~1.1km away — should not be selected
        Landmark::create(['name' => 'Far Church', 'lat' => 14.667, 'lon' => 121.0327, 'poi_type' => 'place_of_worship']);

        $result = (new LandmarkLookupService())->nearest(14.657, 121.0327);

        $this->assertNotNull($result);
        $this->assertSame('Near Pharmacy', $result['name']);
        $this->assertSame('pharmacy', $result['poi_type']);
        $this->assertLessThan(100.0, $result['distance']);
    }

    public function test_returns_null_when_nothing_is_within_range(): void
    {
        // ~1.1km away, outside the 100m radius
        Landmark::create(['name' => 'Far Church', 'lat' => 14.667, 'lon' => 121.0327, 'poi_type' => 'place_of_worship']);

        $result = (new LandmarkLookupService())->nearest(14.657, 121.0327);

        $this->assertNull($result);
    }

    public function test_returns_null_when_no_landmarks_exist(): void
    {
        $result = (new LandmarkLookupService())->nearest(14.657, 121.0327);

        $this->assertNull($result);
    }

    public function test_picks_the_closer_of_two_landmarks_both_within_range(): void
    {
        // ~15m away
        Landmark::create(['name' => 'Closer', 'lat' => 14.6571, 'lon' => 121.0328, 'poi_type' => 'pharmacy']);
        // ~55m away, still within 100m
        Landmark::create(['name' => 'Farther', 'lat' => 14.6575, 'lon' => 121.0327, 'poi_type' => 'fast_food']);

        $result = (new LandmarkLookupService())->nearest(14.657, 121.0327);

        $this->assertSame('Closer', $result['name']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter LandmarkLookupServiceTest`
Expected: FAIL — `Class "App\Services\LandmarkLookupService" not found`.

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace App\Services;

use App\Models\Landmark;

class LandmarkLookupService
{
    private const MAX_DISTANCE_METERS = 100.0;

    private const BOUNDING_BOX_DEGREES = 0.0015;

    /**
     * @return array{name: string, poi_type: string, distance: float}|null
     */
    public function nearest(float $lat, float $lon): ?array
    {
        $candidates = Landmark::query()
            ->whereBetween('lat', [$lat - self::BOUNDING_BOX_DEGREES, $lat + self::BOUNDING_BOX_DEGREES])
            ->whereBetween('lon', [$lon - self::BOUNDING_BOX_DEGREES, $lon + self::BOUNDING_BOX_DEGREES])
            ->get();

        $nearest = null;
        $nearestDistance = null;

        foreach ($candidates as $candidate) {
            $distance = $this->haversineMeters($lat, $lon, $candidate->lat, $candidate->lon);

            if ($distance <= self::MAX_DISTANCE_METERS && ($nearestDistance === null || $distance < $nearestDistance)) {
                $nearest = $candidate;
                $nearestDistance = $distance;
            }
        }

        if ($nearest === null) {
            return null;
        }

        return ['name' => $nearest->name, 'poi_type' => $nearest->poi_type, 'distance' => $nearestDistance];
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
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter LandmarkLookupServiceTest`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Services/LandmarkLookupService.php tests/Feature/LandmarkLookupServiceTest.php
git commit -m "feat: add LandmarkLookupService for nearest-landmark lookup"
```

---

### Task 3: Extend `TripPlannerService`'s query with leg-endpoint coordinates

**Files:**
- Modify: `app/Services/TripPlannerService.php`
- Modify: `tests/Feature/TripPlannerServiceTest.php`

**Interfaces:**
- Produces (consumed by Task 4): each leg's `from`/`to` in the itinerary arrays returned by `TripPlannerService::plan()` now includes `lat`/`lon` alongside `name`.

- [ ] **Step 1: Write the failing test**

Add this test to `tests/Feature/TripPlannerServiceTest.php`, alongside the existing one:

```php
    public function test_requests_lat_and_lon_on_leg_endpoints(): void
    {
        Http::fake([
            '*' => Http::response(['data' => ['plan' => ['itineraries' => []]]]),
        ]);

        (new TripPlannerService())->plan(14.657, 121.0327, 14.5578, 121.0244);

        Http::assertSent(function ($request) {
            $query = $request['query'];

            return str_contains($query, 'from { name lat lon }')
                && str_contains($query, 'to { name lat lon }');
        });
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter TripPlannerServiceTest`
Expected: the new test FAILs (the existing one still passes) — the query doesn't yet request `lat`/`lon`.

- [ ] **Step 3: Extend the GraphQL query**

In `app/Services/TripPlannerService.php`, change:
```php
                        from { name }
                        to { name }
```
to:
```php
                        from { name lat lon }
                        to { name lat lon }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter TripPlannerServiceTest`
Expected: PASS (2 tests)

- [ ] **Step 5: Run the full suite to check for regressions**

Run: `php artisan test`
Expected: PASS, all tests (this touches a shared query string other tests may indirectly depend on via mocked responses — confirm nothing else breaks).

- [ ] **Step 6: Commit**

```bash
git add app/Services/TripPlannerService.php tests/Feature/TripPlannerServiceTest.php
git commit -m "feat: request lat/lon on trip-plan leg endpoints"
```

---

### Task 4: Wire landmark enrichment into `RouteScorer`

**Files:**
- Modify: `app/Services/RouteScorer.php`
- Delete: `tests/Unit/RouteScorerTest.php`
- Create: `tests/Feature/RouteScorerTest.php` (moved and extended from the deleted file)

**Interfaces:**
- Consumes: `LandmarkLookupService::nearest()` (Task 2).
- Produces: nothing further — this is the plan's last task.

**Important context:** `RouteScorer` currently has no constructor and its existing test (`tests/Unit/RouteScorerTest.php`) is a plain `PHPUnit\Framework\TestCase` with no database access, instantiating `new RouteScorer()` directly. Adding a DB-backed `LandmarkLookupService` dependency means this test must move to `tests/Feature/` (matching how `FareEstimatorTest` already handles an analogous DB-backed service) and use `RefreshDatabase`. With an empty `landmarks` table, `LandmarkLookupService::nearest()` always returns `null`, so all 4 existing assertions remain valid unchanged — only the test's class location, base class, and constructor call change.

- [ ] **Step 1: Add the constructor and landmark-enrichment logic to `RouteScorer`**

Replace the full contents of `app/Services/RouteScorer.php` with:

```php
<?php

namespace App\Services;

class RouteScorer
{
    public function __construct(
        private readonly LandmarkLookupService $landmarks,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $itineraries
     * @return array<int, array<string, mixed>> the same itineraries, sorted best-first and
     *                                            augmented with transferCount, difficulty, instructions
     */
    public function rank(array $itineraries): array
    {
        $scored = array_map(function (array $itinerary) {
            $transferCount = $this->transferCount($itinerary['legs']);

            return [
                ...$itinerary,
                'transferCount' => $transferCount,
                'difficulty' => $this->difficulty($transferCount),
                'instructions' => $this->instructions($itinerary['legs']),
            ];
        }, $itineraries);

        usort($scored, fn (array $a, array $b) => $a['transferCount'] <=> $b['transferCount']
            ?: $a['walkDistance'] <=> $b['walkDistance']);

        return $scored;
    }

    /**
     * @param  array<int, array<string, mixed>>  $legs
     */
    private function transferCount(array $legs): int
    {
        $transitLegs = array_filter($legs, fn (array $leg) => ($leg['mode'] ?? null) !== 'WALK');

        return max(0, count($transitLegs) - 1);
    }

    private function difficulty(int $transferCount): string
    {
        return match (true) {
            $transferCount <= 1 => 'Easy',
            $transferCount === 2 => 'Moderate',
            default => 'Hard',
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $legs
     * @return array<int, string>
     */
    private function instructions(array $legs): array
    {
        $lines = [];
        $boardedTransit = false;

        foreach ($legs as $index => $leg) {
            $to = $leg['to']['name'] ?? 'destination';
            $isWalk = ($leg['mode'] ?? null) === 'WALK';

            if ($isWalk) {
                $lines[] = $this->withLandmark("Walk to {$to}", $leg);

                continue;
            }

            $route = $leg['route']['shortName'] ?? $leg['route']['longName'] ?? ucfirst(strtolower($leg['mode']));

            $line = $boardedTransit
                ? "Transfer to {$route} at {$to}"
                : "Ride {$route} to {$to}";

            $lines[] = $index === 0 ? $this->withLandmark($line, $leg) : $line;

            $boardedTransit = true;
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $leg
     */
    private function withLandmark(string $line, array $leg): string
    {
        $lat = $leg['to']['lat'] ?? null;
        $lon = $leg['to']['lon'] ?? null;

        if ($lat === null || $lon === null) {
            return $line;
        }

        $landmark = $this->landmarks->nearest((float) $lat, (float) $lon);

        return $landmark === null ? $line : "{$line} (near {$landmark['name']})";
    }
}
```

- [ ] **Step 2: Delete the old Unit test**

```bash
rm tests/Unit/RouteScorerTest.php
```

- [ ] **Step 3: Create the moved-and-extended Feature test**

```php
<?php

namespace Tests\Feature;

use App\Models\Landmark;
use App\Services\LandmarkLookupService;
use App\Services\RouteScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RouteScorerTest extends TestCase
{
    use RefreshDatabase;

    public function test_ranks_fewest_transfers_first(): void
    {
        $scorer = new RouteScorer(new LandmarkLookupService());

        $itineraries = [
            $this->itinerary(walkDistance: 200.0, legs: [
                ['mode' => 'WALK', 'to' => ['name' => 'Stop A']],
                ['mode' => 'BUS', 'route' => ['shortName' => 'Bus 1'], 'to' => ['name' => 'Stop B']],
                ['mode' => 'RAIL', 'route' => ['shortName' => 'LRT-1'], 'to' => ['name' => 'Stop C']],
                ['mode' => 'WALK', 'to' => ['name' => 'Destination']],
            ]),
            $this->itinerary(walkDistance: 500.0, legs: [
                ['mode' => 'WALK', 'to' => ['name' => 'Stop A']],
                ['mode' => 'BUS', 'route' => ['shortName' => 'Bus 2'], 'to' => ['name' => 'Destination']],
            ]),
        ];

        $ranked = $scorer->rank($itineraries);

        $this->assertSame(0, $ranked[0]['transferCount']);
        $this->assertSame(1, $ranked[1]['transferCount']);
    }

    public function test_uses_walk_distance_as_tiebreaker(): void
    {
        $scorer = new RouteScorer(new LandmarkLookupService());

        $itineraries = [
            $this->itinerary(walkDistance: 800.0, legs: [
                ['mode' => 'WALK', 'to' => ['name' => 'Stop A']],
                ['mode' => 'BUS', 'route' => ['shortName' => 'Bus 1'], 'to' => ['name' => 'Destination']],
            ]),
            $this->itinerary(walkDistance: 200.0, legs: [
                ['mode' => 'WALK', 'to' => ['name' => 'Stop A']],
                ['mode' => 'BUS', 'route' => ['shortName' => 'Bus 2'], 'to' => ['name' => 'Destination']],
            ]),
        ];

        $ranked = $scorer->rank($itineraries);

        $this->assertSame(200.0, $ranked[0]['walkDistance']);
        $this->assertSame(800.0, $ranked[1]['walkDistance']);
    }

    public function test_assigns_difficulty_labels(): void
    {
        $scorer = new RouteScorer(new LandmarkLookupService());

        $noTransfer = $this->itinerary(walkDistance: 100.0, legs: [
            ['mode' => 'WALK', 'to' => ['name' => 'Destination']],
            ['mode' => 'BUS', 'route' => ['shortName' => 'Bus 1'], 'to' => ['name' => 'Destination']],
        ]);
        $twoTransfers = $this->itinerary(walkDistance: 100.0, legs: [
            ['mode' => 'BUS', 'route' => ['shortName' => 'Bus 1'], 'to' => ['name' => 'Stop B']],
            ['mode' => 'RAIL', 'route' => ['shortName' => 'LRT-1'], 'to' => ['name' => 'Stop C']],
            ['mode' => 'BUS', 'route' => ['shortName' => 'Bus 2'], 'to' => ['name' => 'Destination']],
        ]);
        $threeTransfers = $this->itinerary(walkDistance: 100.0, legs: [
            ['mode' => 'BUS', 'route' => ['shortName' => 'Bus 1'], 'to' => ['name' => 'Stop B']],
            ['mode' => 'RAIL', 'route' => ['shortName' => 'LRT-1'], 'to' => ['name' => 'Stop C']],
            ['mode' => 'BUS', 'route' => ['shortName' => 'Bus 2'], 'to' => ['name' => 'Stop D']],
            ['mode' => 'RAIL', 'route' => ['shortName' => 'LRT-2'], 'to' => ['name' => 'Destination']],
        ]);

        $ranked = $scorer->rank([$noTransfer, $twoTransfers, $threeTransfers]);

        $this->assertSame('Easy', $ranked[0]['difficulty']);
        $this->assertSame('Moderate', $ranked[1]['difficulty']);
        $this->assertSame('Hard', $ranked[2]['difficulty']);
    }

    public function test_generates_leg_by_leg_instructions(): void
    {
        $scorer = new RouteScorer(new LandmarkLookupService());

        $itinerary = $this->itinerary(walkDistance: 300.0, legs: [
            ['mode' => 'WALK', 'to' => ['name' => 'SM North EDSA Terminal']],
            ['mode' => 'BUS', 'route' => ['shortName' => 'Jeepney 32'], 'to' => ['name' => 'Quezon Ave']],
            ['mode' => 'RAIL', 'route' => ['shortName' => 'LRT-1'], 'to' => ['name' => 'Roosevelt']],
        ]);

        $ranked = $scorer->rank([$itinerary]);

        $this->assertSame([
            'Walk to SM North EDSA Terminal',
            'Ride Jeepney 32 to Quezon Ave',
            'Transfer to LRT-1 at Roosevelt',
        ], $ranked[0]['instructions']);
    }

    public function test_appends_a_nearby_landmark_to_a_walk_leg(): void
    {
        Landmark::create(['name' => 'Mercury Drug', 'lat' => 14.6571, 'lon' => 121.0328, 'poi_type' => 'pharmacy']);

        $scorer = new RouteScorer(new LandmarkLookupService());

        $itinerary = $this->itinerary(walkDistance: 150.0, legs: [
            ['mode' => 'WALK', 'to' => ['name' => 'Epifanio de los Santos Avenue Corner', 'lat' => 14.657, 'lon' => 121.0327]],
            ['mode' => 'BUS', 'route' => ['shortName' => 'Jeepney 32'], 'to' => ['name' => 'Quezon Ave']],
        ]);

        $ranked = $scorer->rank([$itinerary]);

        $this->assertSame(
            'Walk to Epifanio de los Santos Avenue Corner (near Mercury Drug)',
            $ranked[0]['instructions'][0],
        );
    }

    public function test_leaves_the_instruction_unchanged_when_no_landmark_is_nearby(): void
    {
        $scorer = new RouteScorer(new LandmarkLookupService());

        $itinerary = $this->itinerary(walkDistance: 150.0, legs: [
            ['mode' => 'WALK', 'to' => ['name' => 'Somewhere Remote', 'lat' => 14.657, 'lon' => 121.0327]],
            ['mode' => 'BUS', 'route' => ['shortName' => 'Jeepney 32'], 'to' => ['name' => 'Quezon Ave']],
        ]);

        $ranked = $scorer->rank([$itinerary]);

        $this->assertSame('Walk to Somewhere Remote', $ranked[0]['instructions'][0]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $legs
     * @return array<string, mixed>
     */
    private function itinerary(float $walkDistance, array $legs): array
    {
        return [
            'duration' => 1200,
            'walkDistance' => $walkDistance,
            'legs' => $legs,
        ];
    }
}
```

- [ ] **Step 4: Run tests to verify everything passes**

Run: `php artisan test --filter RouteScorerTest`
Expected: PASS (6 tests)

- [ ] **Step 5: Run the full suite to confirm `TripPlanController` still resolves correctly**

Run: `php artisan test`
Expected: PASS, all tests. `TripPlanController`'s constructor-injected `RouteScorer` resolves via Laravel's automatic dependency injection — no controller change is needed, since `LandmarkLookupService` has no interface/binding to configure and Laravel's container resolves concrete classes automatically. This step confirms that resolution genuinely works end-to-end (via `TripPlanTest`'s existing controller-level tests), not just in isolation.

- [ ] **Step 6: Commit**

```bash
git add app/Services/RouteScorer.php tests/Unit/RouteScorerTest.php tests/Feature/RouteScorerTest.php
git commit -m "feat: enrich walk-leg instructions with nearby OSM landmarks"
```

---

## Success Criteria (from design spec)

- `php artisan osm:extract-landmarks` against the real `metro-manila.osm.pbf` populates `landmarks` with all 7 POI types, every row having a name. — Task 1, Step 5.
- A real trip search's WALK-leg instructions show a landmark suffix when one exists nearby, and are unchanged when none does. — Task 4, Steps 3-4 (unit-level); should also be spot-checked live against a real search once all tasks land.
- Zero behavior change to bus/rail matching, fare estimation, or any previously-shipped feature. — Task 3 Step 5 and Task 4 Step 5 both run the full suite to confirm no regressions.
