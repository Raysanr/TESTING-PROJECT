# Phase 2: Trip Scoring + Real Fares Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rank multiple OTP itineraries by transfer difficulty (Group C) and move fares from a config file to a real DB table (Group B), per `docs/superpowers/specs/2026-08-14-phase-2-trip-scoring-fares-db-design.md`.

**Architecture:** `TripPlannerService` requests N itineraries from OTP instead of 1. A new `RouteScorer` service ranks them and adds `transferCount`/`difficulty`/`instructions`. `FareEstimator` prices each ranked itinerary, now reading fares from a DB table instead of `config/fares.php`. `TripPlanController` returns a ranked `options` array instead of one flat object. Frontend renders a list of option cards instead of one summary.

**Tech Stack:** Laravel 12 (PHP 8.2+), Eloquent/SQLite, PHPUnit, Blade + vanilla JS + Leaflet (Vite).

## Global Constraints

- OTP is requested for exactly 5 itineraries per query (`numItineraries: 5`).
- `transferCount` = count of legs where `mode !== 'WALK'`, minus 1, floored at 0.
- Difficulty labels: `transferCount <= 1 => "Easy"`, `transferCount === 2 => "Moderate"`, `transferCount >= 3 => "Hard"`.
- Ranking sort key: `transferCount` ascending, then `walkDistance` ascending as tiebreaker.
- `fares` table columns: `mode` (string, unique), `base_fare` (decimal 8,2).
- Fare values are unchanged from Phase 1: `jeepney` 13.00, `bus` 13.00, `lrt` 20.00, `mrt` 20.00, `default` 15.00.
- `/api/trip-plan` response shape changes to `{ options: [...] }`; no-route becomes `{ options: [], error: "no_route" }`; OTP-down stays `503 { error: "otp_unavailable" }`.
- Each `option` in the response has: `legs`, `totalFare`, `totalDuration`, `walkDistance`, `transferCount`, `difficulty`, `instructions`.

---

### Task 1: Fares DB table (migration, model, seeder)

**Files:**
- Create: `database/migrations/2026_08_14_120000_create_fares_table.php`
- Create: `app/Models/Fare.php`
- Create: `database/seeders/FareSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Modify: `SETUP.md`
- Test: `tests/Feature/FareSeederTest.php`

**Interfaces:**
- Produces: `App\Models\Fare` (Eloquent model, table `fares`, fillable `mode`, `base_fare`, cast `base_fare` to `float`). `Database\Seeders\FareSeeder` (seeds 5 rows: `jeepney` 13.00, `bus` 13.00, `lrt` 20.00, `mrt` 20.00, `default` 15.00).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/FareSeederTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Fare;
use Database\Seeders\FareSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FareSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_expected_fare_rows(): void
    {
        $this->seed(FareSeeder::class);

        $this->assertSame(13.0, (float) Fare::where('mode', 'jeepney')->value('base_fare'));
        $this->assertSame(13.0, (float) Fare::where('mode', 'bus')->value('base_fare'));
        $this->assertSame(20.0, (float) Fare::where('mode', 'lrt')->value('base_fare'));
        $this->assertSame(20.0, (float) Fare::where('mode', 'mrt')->value('base_fare'));
        $this->assertSame(15.0, (float) Fare::where('mode', 'default')->value('base_fare'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=FareSeederTest`
Expected: FAIL — `Fare` class / `FareSeeder` class / `fares` table don't exist yet.

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_08_14_120000_create_fares_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fares', function (Blueprint $table) {
            $table->id();
            $table->string('mode')->unique();
            $table->decimal('base_fare', 8, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fares');
    }
};
```

- [ ] **Step 4: Create the Fare model**

Create `app/Models/Fare.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Fare extends Model
{
    protected $fillable = ['mode', 'base_fare'];

    protected $casts = [
        'base_fare' => 'float',
    ];
}
```

- [ ] **Step 5: Create the FareSeeder**

Create `database/seeders/FareSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Models\Fare;
use Illuminate\Database\Seeder;

class FareSeeder extends Seeder
{
    public function run(): void
    {
        $fares = [
            'jeepney' => 13.00,
            'bus' => 13.00,
            'lrt' => 20.00,
            'mrt' => 20.00,
            'default' => 15.00,
        ];

        foreach ($fares as $mode => $baseFare) {
            Fare::updateOrCreate(['mode' => $mode], ['base_fare' => $baseFare]);
        }
    }
}
```

- [ ] **Step 6: Wire FareSeeder into DatabaseSeeder**

Modify `database/seeders/DatabaseSeeder.php` — add the call inside `run()`, before the `User::factory()->create(...)` line:

```php
    public function run(): void
    {
        $this->call(FareSeeder::class);

        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --filter=FareSeederTest`
Expected: PASS

- [ ] **Step 8: Apply the migration and seeder to the local dev DB**

Run: `php artisan migrate --seed`
Expected: `create_fares_table` migration runs, `fares` table has 5 rows.

- [ ] **Step 9: Update SETUP.md**

In `SETUP.md`, section "## 4. Configure the app", replace:

```bash
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
```

with:

```bash
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
```

- [ ] **Step 10: Commit**

```bash
git add database/migrations/2026_08_14_120000_create_fares_table.php app/Models/Fare.php database/seeders/FareSeeder.php database/seeders/DatabaseSeeder.php tests/Feature/FareSeederTest.php SETUP.md
git commit -m "Add fares DB table, model, and seeder"
```

---

### Task 2: Migrate FareEstimator to read fares from the DB

**Files:**
- Modify: `app/Services/FareEstimator.php`
- Delete: `config/fares.php`
- Test: `tests/Feature/FareEstimatorTest.php`

**Interfaces:**
- Consumes: `App\Models\Fare` (from Task 1) — `Fare::where('mode', $key)->value('base_fare')`.
- Produces: `FareEstimator::estimate(array $legs): array{legs: array, totalFare: float}` — same signature and output shape as Phase 1, only the data source changed.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/FareEstimatorTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Fare;
use App\Services\FareEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FareEstimatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_prices_legs_against_db_fares(): void
    {
        Fare::create(['mode' => 'jeepney', 'base_fare' => 13.00]);
        Fare::create(['mode' => 'bus', 'base_fare' => 13.00]);
        Fare::create(['mode' => 'lrt', 'base_fare' => 20.00]);
        Fare::create(['mode' => 'mrt', 'base_fare' => 20.00]);
        Fare::create(['mode' => 'default', 'base_fare' => 15.00]);

        $result = (new FareEstimator())->estimate([
            ['mode' => 'WALK', 'distance' => 100.0],
            ['mode' => 'BUS', 'distance' => 3000.0],
            ['mode' => 'SUBWAY', 'distance' => 8000.0],
        ]);

        $this->assertSame(33.0, $result['totalFare']);
        $this->assertSame(0.0, $result['legs'][0]['fare']);
        $this->assertSame(13.0, $result['legs'][1]['fare']);
        $this->assertSame(20.0, $result['legs'][2]['fare']);
    }

    public function test_falls_back_to_default_fare_for_unmapped_mode(): void
    {
        Fare::create(['mode' => 'default', 'base_fare' => 15.00]);

        $result = (new FareEstimator())->estimate([
            ['mode' => 'FERRY', 'distance' => 1000.0],
        ]);

        $this->assertSame(15.0, $result['legs'][0]['fare']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=FareEstimatorTest`
Expected: FAIL — `FareEstimator` still reads from `config('fares...')`, which is empty/undefined in the test's env once we remove reliance on the config file (or, if config still present, the test still doesn't prove DB usage — proceed to Step 3 regardless).

- [ ] **Step 3: Modify FareEstimator**

Replace the body of `app/Services/FareEstimator.php` with:

```php
<?php

namespace App\Services;

use App\Models\Fare;

class FareEstimator
{
    /**
     * Map an OTP leg `mode` to a `fares` table `mode` row.
     *
     * OTP's GTFS-derived `mode` only distinguishes broad categories (WALK, BUS,
     * RAIL, SUBWAY, TRAM, ...), not jeepney-vs-bus or LRT-vs-MRT — that distinction
     * isn't in the feed. Fares are flat per category, so this is a best-effort
     * mapping, not a precise one.
     */
    private const MODE_MAP = [
        'BUS' => 'bus',
        'RAIL' => 'lrt',
        'SUBWAY' => 'lrt',
        'TRAM' => 'lrt',
    ];

    /**
     * @param  array<int, array<string, mixed>>  $legs
     * @return array{legs: array<int, array<string, mixed>>, totalFare: float}
     */
    public function estimate(array $legs): array
    {
        $priced = [];
        $total = 0.0;

        foreach ($legs as $leg) {
            if (($leg['mode'] ?? null) === 'WALK') {
                $priced[] = [...$leg, 'fare' => 0.0];

                continue;
            }

            $fareKey = self::MODE_MAP[$leg['mode'] ?? ''] ?? 'default';
            $fare = (float) (Fare::where('mode', $fareKey)->value('base_fare')
                ?? Fare::where('mode', 'default')->value('base_fare'));

            $priced[] = [...$leg, 'fare' => $fare];
            $total += $fare;
        }

        return [
            'legs' => $priced,
            'totalFare' => $total,
        ];
    }
}
```

- [ ] **Step 4: Delete config/fares.php**

Run: `rm config/fares.php`

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=FareEstimatorTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Services/FareEstimator.php tests/Feature/FareEstimatorTest.php
git rm config/fares.php
git commit -m "Read fares from DB table instead of config file"
```

---

### Task 3: Request multiple itineraries from OTP

**Files:**
- Modify: `app/Services/TripPlannerService.php`
- Test: `tests/Feature/TripPlannerServiceTest.php`

**Interfaces:**
- Produces: `TripPlannerService::plan(float $fromLat, float $fromLon, float $toLat, float $toLon): array` — same signature as Phase 1; now requests 5 itineraries from OTP instead of OTP's default.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TripPlannerServiceTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Services\TripPlannerService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TripPlannerServiceTest extends TestCase
{
    public function test_requests_multiple_itineraries_from_otp(): void
    {
        Http::fake([
            '*' => Http::response(['data' => ['plan' => ['itineraries' => []]]]),
        ]);

        (new TripPlannerService())->plan(14.657, 121.0327, 14.5578, 121.0244);

        Http::assertSent(function ($request) {
            return $request['variables']['numItineraries'] === 5;
        });
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TripPlannerServiceTest`
Expected: FAIL — `variables` has no `numItineraries` key yet.

- [ ] **Step 3: Modify TripPlannerService**

Replace the body of `app/Services/TripPlannerService.php` with:

```php
<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TripPlannerService
{
    private const NUM_ITINERARIES = 5;

    private const QUERY = <<<'GRAPHQL'
        query TripPlan($fromLat: Float!, $fromLon: Float!, $toLat: Float!, $toLon: Float!, $numItineraries: Int!) {
            plan(
                from: { lat: $fromLat, lon: $fromLon }
                to: { lat: $toLat, lon: $toLon }
                transportModes: [{ mode: WALK }, { mode: TRANSIT }]
                numItineraries: $numItineraries
            ) {
                itineraries {
                    duration
                    walkDistance
                    legs {
                        mode
                        startTime
                        endTime
                        distance
                        from { name }
                        to { name }
                        route { shortName longName }
                        legGeometry { points }
                    }
                }
            }
        }
        GRAPHQL;

    /**
     * @return array<int, array<string, mixed>> itineraries, empty when no route was found
     *
     * @throws RuntimeException when OTP is unreachable or returns an error
     */
    public function plan(float $fromLat, float $fromLon, float $toLat, float $toLon): array
    {
        try {
            $response = Http::timeout(10)->post(config('services.otp.url'), [
                'query' => self::QUERY,
                'variables' => [
                    'fromLat' => $fromLat,
                    'fromLon' => $fromLon,
                    'toLat' => $toLat,
                    'toLon' => $toLon,
                    'numItineraries' => self::NUM_ITINERARIES,
                ],
            ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('OTP unreachable: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw new RuntimeException('OTP request failed: '.$response->status());
        }

        $body = $response->json();

        if (isset($body['errors'])) {
            throw new RuntimeException('OTP returned errors: '.json_encode($body['errors']));
        }

        return $body['data']['plan']['itineraries'] ?? [];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=TripPlannerServiceTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/TripPlannerService.php tests/Feature/TripPlannerServiceTest.php
git commit -m "Request 5 itineraries from OTP instead of the default"
```

---

### Task 4: RouteScorer — rank itineraries and generate instructions

**Files:**
- Create: `app/Services/RouteScorer.php`
- Test: `tests/Unit/RouteScorerTest.php`

**Interfaces:**
- Consumes: raw OTP itinerary arrays — each shaped `{duration, walkDistance, legs: [{mode, from: {name}, to: {name}, route: {shortName, longName}, ...}]}` (matches `TripPlannerService::plan()`'s return shape).
- Produces: `RouteScorer::rank(array $itineraries): array` — same itineraries, sorted best-first, each with 3 new keys added: `transferCount` (int), `difficulty` (string: `"Easy"`/`"Moderate"`/`"Hard"`), `instructions` (array of strings).

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/RouteScorerTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Services\RouteScorer;
use PHPUnit\Framework\TestCase;

class RouteScorerTest extends TestCase
{
    public function test_ranks_fewest_transfers_first(): void
    {
        $scorer = new RouteScorer();

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
        $scorer = new RouteScorer();

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
        $scorer = new RouteScorer();

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
        $scorer = new RouteScorer();

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

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=RouteScorerTest`
Expected: FAIL — `App\Services\RouteScorer` doesn't exist yet.

- [ ] **Step 3: Create RouteScorer**

Create `app/Services/RouteScorer.php`:

```php
<?php

namespace App\Services;

class RouteScorer
{
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

        foreach ($legs as $leg) {
            $to = $leg['to']['name'] ?? 'destination';

            if (($leg['mode'] ?? null) === 'WALK') {
                $lines[] = "Walk to {$to}";

                continue;
            }

            $route = $leg['route']['shortName'] ?? $leg['route']['longName'] ?? ucfirst(strtolower($leg['mode']));

            $lines[] = $boardedTransit
                ? "Transfer to {$route} at {$to}"
                : "Ride {$route} to {$to}";

            $boardedTransit = true;
        }

        return $lines;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=RouteScorerTest`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Services/RouteScorer.php tests/Unit/RouteScorerTest.php
git commit -m "Add RouteScorer to rank itineraries by transfer difficulty"
```

---

### Task 5: Wire ranking + DB fares into TripPlanController

**Files:**
- Modify: `app/Http/Controllers/TripPlanController.php`
- Modify: `tests/Feature/TripPlanTest.php`

**Interfaces:**
- Consumes: `TripPlannerService::plan(...): array` (Task 3), `RouteScorer::rank(array): array` (Task 4), `FareEstimator::estimate(array): array{legs, totalFare}` (Task 2).
- Produces: `GET /api/trip-plan` now returns `{ options: [ { legs, totalFare, totalDuration, walkDistance, transferCount, difficulty, instructions }, ... ] }`, or `{ options: [], error: "no_route" }`, or `503 { error: "otp_unavailable" }`.

- [ ] **Step 1: Write the failing test**

Replace the full contents of `tests/Feature/TripPlanTest.php`:

```php
<?php

namespace Tests\Feature;

use Database\Seeders\FareSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TripPlanTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/trip-plan?from_lat=14.657&from_lon=121.0327&to_lat=14.5578&to_lon=121.0244';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FareSeeder::class);
    }

    public function test_returns_ranked_priced_options_on_success(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [
                    'plan' => [
                        'itineraries' => [
                            [
                                'duration' => 3600,
                                'walkDistance' => 900.0,
                                'legs' => [
                                    ['mode' => 'WALK', 'distance' => 300.0, 'to' => ['name' => 'Stop A']],
                                    ['mode' => 'BUS', 'distance' => 3000.0, 'route' => ['shortName' => 'Bus 1'], 'to' => ['name' => 'Stop B']],
                                    ['mode' => 'SUBWAY', 'distance' => 8000.0, 'route' => ['shortName' => 'MRT-3'], 'to' => ['name' => 'Stop C']],
                                    ['mode' => 'BUS', 'distance' => 2000.0, 'route' => ['shortName' => 'Bus 2'], 'to' => ['name' => 'Destination']],
                                ],
                            ],
                            [
                                'duration' => 2520,
                                'walkDistance' => 450.0,
                                'legs' => [
                                    ['mode' => 'WALK', 'distance' => 450.0, 'to' => ['name' => 'Stop A']],
                                    ['mode' => 'BUS', 'distance' => 3000.0, 'route' => ['shortName' => 'Bus 1'], 'to' => ['name' => 'Destination']],
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $response = $this->getJson(self::ENDPOINT);

        $response->assertOk();
        $response->assertJsonCount(2, 'options');

        $response->assertJsonPath('options.0.transferCount', 0);
        $response->assertJsonPath('options.0.totalFare', 13.0);
        $response->assertJsonPath('options.0.difficulty', 'Easy');
        $response->assertJsonPath('options.1.transferCount', 2);
        $response->assertJsonPath('options.1.totalFare', 13.0 + 20.0 + 13.0);
        $response->assertJsonPath('options.1.difficulty', 'Moderate');
    }

    public function test_returns_no_route_when_otp_finds_nothing(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => ['plan' => ['itineraries' => []]],
            ]),
        ]);

        $response = $this->getJson(self::ENDPOINT);

        $response->assertOk()->assertJson([
            'options' => [],
            'error' => 'no_route',
        ]);
    }

    public function test_returns_503_when_otp_is_unreachable(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });

        $response = $this->getJson(self::ENDPOINT);

        $response->assertStatus(503)->assertJson([
            'error' => 'otp_unavailable',
        ]);
    }

    public function test_validates_required_coordinates(): void
    {
        $response = $this->getJson('/api/trip-plan');

        $response->assertStatus(422);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TripPlanTest`
Expected: FAIL — controller still returns a flat single-object shape, no `options` key.

- [ ] **Step 3: Modify TripPlanController**

Replace the full contents of `app/Http/Controllers/TripPlanController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Services\FareEstimator;
use App\Services\RouteScorer;
use App\Services\TripPlannerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class TripPlanController extends Controller
{
    public function __construct(
        private readonly TripPlannerService $planner,
        private readonly RouteScorer $scorer,
        private readonly FareEstimator $fares,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_lat' => ['required', 'numeric'],
            'from_lon' => ['required', 'numeric'],
            'to_lat' => ['required', 'numeric'],
            'to_lon' => ['required', 'numeric'],
        ]);

        try {
            $itineraries = $this->planner->plan(
                $data['from_lat'],
                $data['from_lon'],
                $data['to_lat'],
                $data['to_lon'],
            );
        } catch (RuntimeException) {
            return response()->json([
                'error' => 'otp_unavailable',
            ], 503);
        }

        if (empty($itineraries)) {
            return response()->json([
                'options' => [],
                'error' => 'no_route',
            ]);
        }

        $ranked = $this->scorer->rank($itineraries);

        $options = array_map(function (array $itinerary) {
            $priced = $this->fares->estimate($itinerary['legs']);

            return [
                'legs' => $priced['legs'],
                'totalFare' => $priced['totalFare'],
                'totalDuration' => $itinerary['duration'],
                'walkDistance' => $itinerary['walkDistance'],
                'transferCount' => $itinerary['transferCount'],
                'difficulty' => $itinerary['difficulty'],
                'instructions' => $itinerary['instructions'],
            ];
        }, $ranked);

        return response()->json(['options' => $options]);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=TripPlanTest`
Expected: PASS (4 tests)

- [ ] **Step 5: Run the full test suite**

Run: `php artisan test`
Expected: PASS (all tests, including Tasks 1-4's tests)

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/TripPlanController.php tests/Feature/TripPlanTest.php
git commit -m "Return ranked, priced itinerary options from /api/trip-plan"
```

---

### Task 6: Frontend — ranked option list

**Files:**
- Modify: `resources/views/trip-planner.blade.php`
- Modify: `resources/js/app.js`

**Interfaces:**
- Consumes: `GET /api/trip-plan` response shape from Task 5 — `{ options: [ { legs, totalFare, totalDuration, walkDistance, transferCount, difficulty, instructions }, ... ] }`.

No automated test for this task — per the spec's Testing section, frontend verification is a manual browser check (Step 4 below), matching Phase 1's approach (no JS test framework in this stack).

- [ ] **Step 1: Update the Blade view**

In `resources/views/trip-planner.blade.php`, replace the `<div id="results" ...>...</div>` block with:

```html
            <div id="results" class="flex flex-col gap-4">
                <ol id="options-list" class="flex flex-col gap-3"></ol>

                <p class="text-xs text-foreground-secondary pt-2 border-t border-black/10 dark:border-white/10">
                    Live from <code>/api/trip-plan</code> — requires OTP to be running with a built graph.
                </p>
            </div>
```

- [ ] **Step 2: Update app.js result rendering**

In `resources/js/app.js`, replace the block from `const statusEl = document.getElementById('status-message');` through the end of `renderItinerary(...)` (i.e. everything up to but not including `async function findRoute() {`) with:

```javascript
const statusEl = document.getElementById('status-message');
const resultsEl = document.getElementById('results');
const optionsListEl = document.getElementById('options-list');

const MODE_ICON = {
    WALK: 'Footprints',
    BUS: 'Bus',
    RAIL: 'TrainFront',
    SUBWAY: 'TrainFront',
    TRAM: 'TrainFront',
};

const MODE_COLOR = {
    WALK: '#6d6d6d',
    BUS: '#171717',
    RAIL: '#b91c1c',
    SUBWAY: '#b91c1c',
    TRAM: '#b91c1c',
};

function setStatus(message) {
    if (!message) {
        statusEl.classList.add('hidden');
        statusEl.textContent = '';
        resultsEl.classList.remove('hidden');

        return;
    }

    statusEl.textContent = message;
    statusEl.classList.remove('hidden');
    resultsEl.classList.add('hidden');
}

function drawRoute(legs) {
    if (!routeLayer) {
        return;
    }

    routeLayer.clearLayers();

    const allPoints = [];

    for (const leg of legs) {
        const points = leg.legGeometry?.points ? decodePolyline(leg.legGeometry.points) : [];

        if (points.length === 0) {
            continue;
        }

        allPoints.push(...points);

        L.polyline(points, {
            color: MODE_COLOR[leg.mode] ?? '#171717',
            weight: leg.mode === 'WALK' ? 3 : 5,
            opacity: leg.mode === 'WALK' ? 0.6 : 0.85,
            dashArray: leg.mode === 'WALK' ? '6 6' : null,
        }).addTo(routeLayer);
    }

    if (allPoints.length > 0) {
        L.circleMarker(allPoints[0], { radius: 7, color: '#171717', fillColor: '#fbfbfb', fillOpacity: 1, weight: 2 })
            .addTo(routeLayer)
            .bindTooltip(legs[0]?.from?.name ?? 'Origin');

        L.circleMarker(allPoints[allPoints.length - 1], { radius: 7, color: '#171717', fillColor: '#fbfbfb', fillOpacity: 1, weight: 2 })
            .addTo(routeLayer)
            .bindTooltip(legs[legs.length - 1]?.to?.name ?? 'Destination');

        map.fitBounds(L.latLngBounds(allPoints), { padding: [40, 40] });
    }
}

function legLine(leg) {
    const iconName = MODE_ICON[leg.mode] ?? 'Bus';
    const label = leg.route?.shortName ?? leg.route?.longName ?? leg.mode;
    const fareLabel = leg.fare > 0 ? ` · ₱${Number(leg.fare).toFixed(2)}` : '';
    const kebabIcon = iconName.replace(/[A-Z]/g, (m, i) => (i ? '-' : '') + m.toLowerCase());

    return `
        <i data-lucide="${kebabIcon}" class="w-4 h-4 mt-0.5 shrink-0"></i>
        <div>
            <p class="font-medium">${label}</p>
            <p class="text-foreground-secondary">${leg.from?.name ?? ''} → ${leg.to?.name ?? ''}${fareLabel}</p>
        </div>
    `;
}

function selectOption(option, cardEl) {
    for (const el of optionsListEl.querySelectorAll('[data-option-card]')) {
        el.classList.remove('border-foreground/40');
        el.querySelector('[data-option-detail]').classList.add('hidden');
    }

    cardEl.classList.add('border-foreground/40');
    cardEl.querySelector('[data-option-detail]').classList.remove('hidden');

    drawRoute(option.legs);
}

function renderOptions(options) {
    optionsListEl.innerHTML = '';

    options.forEach((option, index) => {
        const li = document.createElement('li');
        li.dataset.optionCard = 'true';
        li.className = 'rounded-lg border border-black/10 dark:border-white/10 p-3 cursor-pointer flex flex-col gap-2';

        const legsHtml = option.legs
            .filter((leg) => !(leg.mode === 'WALK' && leg.distance < 50))
            .map((leg) => `<li class="flex gap-3 text-sm">${legLine(leg)}</li>`)
            .join('');

        const instructionsHtml = option.instructions.map((line) => `<li>${line}</li>`).join('');
        const transferLabel = `${option.transferCount} transfer${option.transferCount === 1 ? '' : 's'}`;

        li.innerHTML = `
            <div class="flex items-center justify-between text-sm">
                <span class="inline-flex items-center gap-2">
                    <span class="text-base font-semibold">₱${Number(option.totalFare).toFixed(2)}</span>
                    <span class="text-foreground-secondary">${Math.round(option.totalDuration / 60)} min</span>
                </span>
                <span class="text-xs rounded-full border border-black/10 dark:border-white/10 px-2 py-0.5">${option.difficulty}</span>
            </div>
            <p class="text-xs text-foreground-secondary">${transferLabel}</p>
            <div data-option-detail class="hidden flex flex-col gap-3 pt-2 border-t border-black/10 dark:border-white/10">
                <ol class="flex flex-col gap-1 text-xs text-foreground-secondary list-decimal list-inside">${instructionsHtml}</ol>
                <ol class="flex flex-col gap-3">${legsHtml}</ol>
            </div>
        `;

        li.addEventListener('click', () => selectOption(option, li));
        optionsListEl.appendChild(li);

        if (index === 0) {
            selectOption(option, li);
        }
    });

    createIcons({ icons: ICONS });
}
```

- [ ] **Step 3: Update findRoute to call renderOptions**

In `resources/js/app.js`, inside `async function findRoute() { ... }`, replace:

```javascript
        setStatus(null);
        renderItinerary(data);
```

with:

```javascript
        setStatus(null);
        renderOptions(data.options);
```

- [ ] **Step 4: Manual browser check**

Start the stack (Docker OTP + `npm run dev` + `php artisan serve`, per `SETUP.md`), open `http://localhost:8000`, and search "SM North EDSA" → "Ayala Avenue, Makati" (the default prefilled values). Confirm:
- A list of ranked option cards appears (fare, ETA, difficulty badge, transfer count per card).
- The first (best-ranked) card is pre-selected and its route is drawn on the map.
- Clicking a different card redraws the map with that option's route and expands its instructions/legs.

- [ ] **Step 5: Commit**

```bash
git add resources/views/trip-planner.blade.php resources/js/app.js
git commit -m "Render ranked itinerary options in the frontend"
```
