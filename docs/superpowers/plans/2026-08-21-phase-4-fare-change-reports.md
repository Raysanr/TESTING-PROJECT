# Phase 4: Fare Change Reports Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a rider report a fare that differs from what the app quoted; auto-update the live fare once enough independent reports agree, with no accounts and no manual moderation.

**Architecture:** A new `fare_reports` table logs every submission unconditionally. A `FareCorroborationService` runs synchronously after each insert, checking whether the mode now has enough agreeing recent reports to update `fares.base_fare` directly — the same table `FareEstimator` already reads (Phase 2). No queue, no admin UI, no auth.

**Tech Stack:** Laravel (existing `fares` table/model from Phase 2), vanilla JS (existing `resources/js/app.js`), no new dependencies.

## Global Constraints

- No accounts, no login, no admin dashboard — this app has none and Phase 4 adds none. Corroboration is the only abuse control (see Task 2).
- `MIN_CORROBORATING_REPORTS = 3`, `TOLERANCE = 1.00` (pesos), `WINDOW_DAYS = 14` — exact values from the design spec, defined as named class constants, never inline magic numbers.
- `reported_fare` sanity bounds: `numeric`, `between:1,200`.
- Rate limit `/api/fare-reports` to `throttle:10,1` (10 requests/minute per IP) — Laravel's built-in `throttle` middleware alias, no extra provider config needed since `routes/api.php` is already registered via `bootstrap/app.php`'s `withRouting(api: ...)`.
- No new npm dependency. No JS test runner in this repo — frontend verification is manual browser/DevTools checks, per this project's established convention (Phases 1-3 all used this).
- Follow existing code style: PHP services are plain classes with one public method where possible (see `FareEstimator`, `RouteScorer`); PHPUnit tests use `RefreshDatabase` + `Tests\TestCase` (see `tests/Feature/FareEstimatorTest.php`); frontend JS is plain functions, Tailwind utility classes matching `trip-planner.blade.php`'s existing conventions.

---

## File Structure

- `database/migrations/<timestamp>_create_fare_reports_table.php` — **new**. `fare_reports` table.
- `app/Models/FareReport.php` — **new**. Eloquent model, no relationships.
- `app/Services/FareCorroborationService.php` — **new**. `evaluate(string $mode): ?float` — the only piece with real logic; kept separate from the controller so it's unit-testable without HTTP.
- `app/Http/Controllers/FareReportController.php` — **new**. Thin: validate, log, delegate to the service, respond.
- `app/Services/FareEstimator.php` — **modified**. Adds `fareMode` to each priced leg so the frontend can echo the correct mode back without re-deriving `MODE_MAP` client-side.
- `routes/api.php` — **modified**. Adds the `POST /fare-reports` route with rate limiting.
- `resources/js/app.js` — **modified**. Adds the "Report fare" inline affordance per leg.
- `tests/Feature/FareCorroborationServiceTest.php`, `tests/Feature/FareReportControllerTest.php` — **new**.
- `tests/Feature/FareEstimatorTest.php` — **modified**. Extends existing assertions to cover the new `fareMode` field.

---

### Task 1: `fare_reports` table and model

**Files:**
- Create: `database/migrations/2026_08_21_140000_create_fare_reports_table.php`
- Create: `app/Models/FareReport.php`
- Test: `tests/Feature/FareReportModelTest.php`

**Interfaces:**
- Produces (consumed by Task 2 and Task 3):
  - `FareReport` model, `$fillable = ['mode', 'reported_fare', 'client_hash']`, casts `reported_fare` to `float`.
  - Table columns: `id`, `mode` (string, indexed), `reported_fare` (decimal 8,2), `client_hash` (string, nullable), `applied` (boolean, default false), `created_at` (timestamp, no `updated_at` — reports are immutable).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\FareReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FareReportModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_fare_report_with_expected_defaults(): void
    {
        $report = FareReport::create([
            'mode' => 'jeepney',
            'reported_fare' => 14.00,
        ]);

        $this->assertSame('jeepney', $report->mode);
        $this->assertSame(14.0, $report->reported_fare);
        $this->assertFalse((bool) $report->applied);
        $this->assertNull($report->client_hash);
        $this->assertNotNull($report->created_at);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter FareReportModelTest`
Expected: FAIL — `Class "App\Models\FareReport" not found` (migration and model don't exist yet).

- [ ] **Step 3: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fare_reports', function (Blueprint $table) {
            $table->id();
            $table->string('mode')->index();
            $table->decimal('reported_fare', 8, 2);
            $table->string('client_hash')->nullable();
            $table->boolean('applied')->default(false);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fare_reports');
    }
};
```

- [ ] **Step 4: Create the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FareReport extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['mode', 'reported_fare', 'client_hash'];

    protected $casts = [
        'reported_fare' => 'float',
        'applied' => 'boolean',
    ];
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter FareReportModelTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_08_21_140000_create_fare_reports_table.php app/Models/FareReport.php tests/Feature/FareReportModelTest.php
git commit -m "feat: add fare_reports table and model"
```

---

### Task 2: `FareCorroborationService`

**Files:**
- Create: `app/Services/FareCorroborationService.php`
- Test: `tests/Feature/FareCorroborationServiceTest.php`

**Interfaces:**
- Consumes: `FareReport` model (Task 1), `Fare` model (existing, `app/Models/Fare.php`).
- Produces (consumed by Task 3):
  - `FareCorroborationService::evaluate(string $mode): ?float` — returns the new fare if corroboration fired (and updates `fares.base_fare` + marks the corroborating reports `applied = true` as a side effect, inside a DB transaction), or `null` if it didn't (no side effects in that case).

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature;

use App\Models\Fare;
use App\Models\FareReport;
use App\Services\FareCorroborationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FareCorroborationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_does_not_update_with_fewer_than_three_reports(): void
    {
        Fare::create(['mode' => 'jeepney', 'base_fare' => 13.00]);
        FareReport::create(['mode' => 'jeepney', 'reported_fare' => 15.00]);
        FareReport::create(['mode' => 'jeepney', 'reported_fare' => 15.00]);

        $result = (new FareCorroborationService())->evaluate('jeepney');

        $this->assertNull($result);
        $this->assertSame(13.0, Fare::where('mode', 'jeepney')->value('base_fare'));
    }

    public function test_does_not_update_when_reports_disagree_beyond_tolerance(): void
    {
        Fare::create(['mode' => 'jeepney', 'base_fare' => 13.00]);
        FareReport::create(['mode' => 'jeepney', 'reported_fare' => 15.00]);
        FareReport::create(['mode' => 'jeepney', 'reported_fare' => 18.00]);
        FareReport::create(['mode' => 'jeepney', 'reported_fare' => 22.00]);

        $result = (new FareCorroborationService())->evaluate('jeepney');

        $this->assertNull($result);
        $this->assertSame(13.0, Fare::where('mode', 'jeepney')->value('base_fare'));
    }

    public function test_updates_fare_when_three_reports_agree_within_tolerance(): void
    {
        Fare::create(['mode' => 'jeepney', 'base_fare' => 13.00]);
        $r1 = FareReport::create(['mode' => 'jeepney', 'reported_fare' => 15.00]);
        $r2 = FareReport::create(['mode' => 'jeepney', 'reported_fare' => 15.50]);
        $r3 = FareReport::create(['mode' => 'jeepney', 'reported_fare' => 14.50]);

        $result = (new FareCorroborationService())->evaluate('jeepney');

        $this->assertSame(15.0, $result);
        $this->assertSame(15.0, Fare::where('mode', 'jeepney')->value('base_fare'));
        $this->assertTrue($r1->fresh()->applied);
        $this->assertTrue($r2->fresh()->applied);
        $this->assertTrue($r3->fresh()->applied);
    }

    public function test_ignores_reports_older_than_the_window(): void
    {
        Fare::create(['mode' => 'jeepney', 'base_fare' => 13.00]);

        FareReport::create(['mode' => 'jeepney', 'reported_fare' => 15.00, 'created_at' => now()->subDays(20)]);
        FareReport::create(['mode' => 'jeepney', 'reported_fare' => 15.00, 'created_at' => now()->subDays(20)]);
        FareReport::create(['mode' => 'jeepney', 'reported_fare' => 15.00, 'created_at' => now()->subDays(20)]);

        $result = (new FareCorroborationService())->evaluate('jeepney');

        $this->assertNull($result);
        $this->assertSame(13.0, Fare::where('mode', 'jeepney')->value('base_fare'));
    }

    public function test_ignores_already_applied_reports_when_counting(): void
    {
        Fare::create(['mode' => 'jeepney', 'base_fare' => 13.00]);

        FareReport::create(['mode' => 'jeepney', 'reported_fare' => 15.00, 'applied' => true]);
        FareReport::create(['mode' => 'jeepney', 'reported_fare' => 15.00, 'applied' => true]);
        FareReport::create(['mode' => 'jeepney', 'reported_fare' => 15.00, 'applied' => true]);
        FareReport::create(['mode' => 'jeepney', 'reported_fare' => 15.00]);

        $result = (new FareCorroborationService())->evaluate('jeepney');

        $this->assertNull($result);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter FareCorroborationServiceTest`
Expected: FAIL — `Class "App\Services\FareCorroborationService" not found`.

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace App\Services;

use App\Models\Fare;
use App\Models\FareReport;
use Illuminate\Support\Facades\DB;

class FareCorroborationService
{
    private const MIN_CORROBORATING_REPORTS = 3;

    private const TOLERANCE = 1.00;

    private const WINDOW_DAYS = 14;

    public function evaluate(string $mode): ?float
    {
        $reports = FareReport::where('mode', $mode)
            ->where('applied', false)
            ->where('created_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->orderBy('created_at')
            ->get();

        if ($reports->count() < self::MIN_CORROBORATING_REPORTS) {
            return null;
        }

        $agreeing = $this->findAgreeingGroup($reports);

        if ($agreeing === null) {
            return null;
        }

        $newFare = round($agreeing->avg('reported_fare'), 2);

        DB::transaction(function () use ($mode, $newFare, $agreeing) {
            Fare::where('mode', $mode)->update(['base_fare' => $newFare]);
            FareReport::whereIn('id', $agreeing->pluck('id'))->update(['applied' => true]);
        });

        return $newFare;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, FareReport>  $reports
     * @return \Illuminate\Support\Collection<int, FareReport>|null
     */
    private function findAgreeingGroup($reports)
    {
        foreach ($reports as $anchor) {
            $group = $reports->filter(
                fn (FareReport $report) => abs($report->reported_fare - $anchor->reported_fare) <= self::TOLERANCE
            );

            if ($group->count() >= self::MIN_CORROBORATING_REPORTS) {
                return $group;
            }
        }

        return null;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter FareCorroborationServiceTest`
Expected: PASS (5 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Services/FareCorroborationService.php tests/Feature/FareCorroborationServiceTest.php
git commit -m "feat: add FareCorroborationService to auto-update fares from agreeing reports"
```

---

### Task 3: `FareReportController`, route, and `FareEstimator.fareMode`

**Files:**
- Create: `app/Http/Controllers/FareReportController.php`
- Create: `tests/Feature/FareReportControllerTest.php`
- Modify: `routes/api.php`
- Modify: `app/Services/FareEstimator.php`
- Modify: `tests/Feature/FareEstimatorTest.php`

**Interfaces:**
- Consumes: `FareReport` model, `Fare` model, `FareCorroborationService::evaluate(string $mode): ?float` (all from Tasks 1-2).
- Produces: `POST /api/fare-reports` — request `{ mode: string, reported_fare: number }`, response `{ status: 'recorded'|'updated', currentFare: number }` (200) or Laravel's standard 422 validation-error shape. `FareEstimator::estimate()`'s returned legs each gain a `fareMode` string field (consumed by Task 4's frontend).

- [ ] **Step 1: Write the failing controller tests**

```php
<?php

namespace Tests\Feature;

use App\Models\Fare;
use App\Models\FareReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FareReportControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_records_a_valid_report_without_updating_fare_yet(): void
    {
        Fare::create(['mode' => 'jeepney', 'base_fare' => 13.00]);

        $response = $this->postJson('/api/fare-reports', [
            'mode' => 'jeepney',
            'reported_fare' => 15.00,
        ]);

        $response->assertStatus(200)->assertJson([
            'status' => 'recorded',
            'currentFare' => 13.0,
        ]);
        $this->assertDatabaseHas('fare_reports', ['mode' => 'jeepney', 'reported_fare' => 15.00]);
    }

    public function test_reports_completing_corroboration_return_updated_status(): void
    {
        Fare::create(['mode' => 'jeepney', 'base_fare' => 13.00]);
        FareReport::create(['mode' => 'jeepney', 'reported_fare' => 15.00]);
        FareReport::create(['mode' => 'jeepney', 'reported_fare' => 15.00]);

        $response = $this->postJson('/api/fare-reports', [
            'mode' => 'jeepney',
            'reported_fare' => 15.00,
        ]);

        $response->assertStatus(200)->assertJson([
            'status' => 'updated',
            'currentFare' => 15.0,
        ]);
        $this->assertSame(15.0, Fare::where('mode', 'jeepney')->value('base_fare'));
    }

    public function test_rejects_a_mode_not_in_the_fares_table(): void
    {
        $response = $this->postJson('/api/fare-reports', [
            'mode' => 'not-a-real-mode',
            'reported_fare' => 15.00,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['mode']);
    }

    public function test_rejects_a_reported_fare_outside_sanity_bounds(): void
    {
        Fare::create(['mode' => 'jeepney', 'base_fare' => 13.00]);

        $response = $this->postJson('/api/fare-reports', [
            'mode' => 'jeepney',
            'reported_fare' => 500.00,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['reported_fare']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter FareReportControllerTest`
Expected: FAIL — route `/api/fare-reports` doesn't exist (404s, assertion mismatches).

- [ ] **Step 3: Write the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Models\Fare;
use App\Models\FareReport;
use App\Services\FareCorroborationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FareReportController extends Controller
{
    public function __construct(
        private readonly FareCorroborationService $corroboration,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', 'string', Rule::exists('fares', 'mode')],
            'reported_fare' => ['required', 'numeric', 'between:1,200'],
        ]);

        FareReport::create([
            'mode' => $data['mode'],
            'reported_fare' => $data['reported_fare'],
            'client_hash' => hash('sha256', $request->ip().'|'.$request->userAgent()),
        ]);

        $updatedFare = $this->corroboration->evaluate($data['mode']);

        if ($updatedFare !== null) {
            return response()->json(['status' => 'updated', 'currentFare' => $updatedFare]);
        }

        $currentFare = (float) Fare::where('mode', $data['mode'])->value('base_fare');

        return response()->json(['status' => 'recorded', 'currentFare' => $currentFare]);
    }
}
```

- [ ] **Step 4: Add the route**

In `routes/api.php`, add the import and route:

```php
use App\Http\Controllers\FareReportController;
```

```php
Route::post('/fare-reports', [FareReportController::class, 'store'])->middleware('throttle:10,1');
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter FareReportControllerTest`
Expected: PASS (4 tests)

- [ ] **Step 6: Add the failing `fareMode` assertion to the existing `FareEstimatorTest`**

Add this test to `tests/Feature/FareEstimatorTest.php` (alongside the existing two):

```php
    public function test_includes_fare_mode_on_each_priced_leg(): void
    {
        Fare::create(['mode' => 'bus', 'base_fare' => 13.00]);
        Fare::create(['mode' => 'default', 'base_fare' => 15.00]);

        $result = (new FareEstimator())->estimate([
            ['mode' => 'WALK', 'distance' => 100.0],
            ['mode' => 'BUS', 'distance' => 3000.0],
        ]);

        $this->assertNull($result['legs'][0]['fareMode']);
        $this->assertSame('bus', $result['legs'][1]['fareMode']);
    }
```

- [ ] **Step 7: Run to verify it fails**

Run: `php artisan test --filter FareEstimatorTest`
Expected: FAIL — `Undefined array key "fareMode"`.

- [ ] **Step 8: Add `fareMode` to `FareEstimator::estimate()`**

In `app/Services/FareEstimator.php`, modify the `estimate` method:

```php
    public function estimate(array $legs): array
    {
        $priced = [];
        $total = 0.0;

        foreach ($legs as $leg) {
            if (($leg['mode'] ?? null) === 'WALK') {
                $priced[] = [...$leg, 'fare' => 0.0, 'fareMode' => null];

                continue;
            }

            $fareKey = self::MODE_MAP[$leg['mode'] ?? ''] ?? 'default';
            $fare = (float) (Fare::where('mode', $fareKey)->value('base_fare')
                ?? Fare::where('mode', 'default')->value('base_fare'));

            $priced[] = [...$leg, 'fare' => $fare, 'fareMode' => $fareKey];
            $total += $fare;
        }

        return [
            'legs' => $priced,
            'totalFare' => $total,
        ];
    }
```

- [ ] **Step 9: Run all fare tests to verify they pass**

Run: `php artisan test --filter FareEstimatorTest`
Expected: PASS (3 tests)

- [ ] **Step 10: Run the full suite to check for regressions**

Run: `php artisan test`
Expected: PASS, all tests (existing Phase 1-3 tests plus this task's new ones).

- [ ] **Step 11: Commit**

```bash
git add app/Http/Controllers/FareReportController.php app/Services/FareEstimator.php routes/api.php tests/Feature/FareReportControllerTest.php tests/Feature/FareEstimatorTest.php
git commit -m "feat: add fare-reports endpoint and fareMode field on priced legs"
```

---

### Task 4: "Report fare" UI

**Files:**
- Modify: `resources/js/app.js`

**Interfaces:**
- Consumes: `leg.fareMode` (Task 3's new field on each priced leg in the `/api/trip-plan` response), `leg.fare`, `leg.from`/`leg.to`/`leg.mode` (existing, used by `legLine()`).
- Produces: nothing consumed by another task — this is the plan's last task.

- [ ] **Step 1: Add the "Report fare" affordance to `legLine()`**

In `resources/js/app.js`, replace the `legLine` function with:

```js
function legLine(leg) {
    const iconName = MODE_ICON[leg.mode] ?? 'Bus';
    const label = leg.route?.shortName ?? leg.route?.longName ?? leg.mode;
    const fareLabel = leg.fare > 0 ? ` · ₱${Number(leg.fare).toFixed(2)}` : '';
    const kebabIcon = iconName.replace(/[A-Z]/g, (m, i) => (i ? '-' : '') + m.toLowerCase());
    const reportHtml = leg.fare > 0 && leg.fareMode
        ? `<button type="button" data-report-fare-btn data-fare-mode="${leg.fareMode}" data-current-fare="${leg.fare}" class="text-xs text-foreground-secondary underline underline-offset-2">Report fare</button>`
        : '';

    return `
        <i data-lucide="${kebabIcon}" class="w-4 h-4 mt-0.5 shrink-0"></i>
        <div class="flex-1">
            <p class="font-medium">${label}</p>
            <p class="text-foreground-secondary">${leg.from?.name ?? ''} → ${leg.to?.name ?? ''}${fareLabel}</p>
            <div data-report-fare-container>${reportHtml}</div>
        </div>
    `;
}
```

- [ ] **Step 2: Wire the report-fare click handler and inline form**

Add this after `legLine` and before `renderOptions` in `app.js`:

```js
function attachReportFareHandlers(container) {
    for (const btn of container.querySelectorAll('[data-report-fare-btn]')) {
        btn.addEventListener('click', () => showReportFareForm(btn));
    }
}

function showReportFareForm(btn) {
    const mode = btn.dataset.fareMode;
    const currentFare = btn.dataset.currentFare;
    const container = btn.closest('[data-report-fare-container]');

    container.innerHTML = `
        <div class="flex items-center gap-2 mt-1">
            <input type="number" step="0.01" min="1" max="200" value="${currentFare}"
                data-report-fare-input
                class="w-20 rounded border border-black/10 dark:border-white/10 bg-transparent px-2 py-1 text-xs outline-none focus:border-foreground/40">
            <button type="button" data-report-fare-submit class="text-xs underline underline-offset-2">Submit</button>
        </div>
    `;

    container.querySelector('[data-report-fare-submit]').addEventListener('click', () => submitFareReport(mode, container));
}

async function submitFareReport(mode, container) {
    const input = container.querySelector('[data-report-fare-input]');
    const reportedFare = Number(input.value);

    try {
        const response = await fetch('/api/fare-reports', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ mode, reported_fare: reportedFare }),
        });

        if (!response.ok) {
            container.innerHTML = '<p class="text-xs text-foreground-secondary mt-1">Couldn\'t submit that report.</p>';

            return;
        }

        const data = await response.json();
        const message = data.status === 'updated'
            ? `Thanks — fares just updated to ₱${Number(data.currentFare).toFixed(2)}.`
            : 'Thanks — reported.';

        container.innerHTML = `<p class="text-xs text-foreground-secondary mt-1">${message}</p>`;
    } catch {
        container.innerHTML = '<p class="text-xs text-foreground-secondary mt-1">Couldn\'t submit that report.</p>';
    }
}
```

- [ ] **Step 3: Call `attachReportFareHandlers` after rendering legs**

In `renderOptions`, find the line that appends `li` to `optionsListEl` (`optionsListEl.appendChild(li);`) and add immediately after it:

```js
optionsListEl.appendChild(li);
attachReportFareHandlers(li);
```

- [ ] **Step 4: Verify in the browser**

With `npm run dev`, `php artisan serve` running (OTP not required — this can be tested by stubbing `fetch` in the console the same way earlier phases' offline paths were tested, or with OTP running for a real result):

1. Load a route that renders at least one non-WALK leg.
2. Click "Report fare" on a leg — confirm the inline number input (pre-filled with the current fare) and "Submit" button appear, replacing the link.
3. Change the value, click Submit — confirm the network tab shows `POST /api/fare-reports` and the UI shows "Thanks — reported."
4. Repeat 2 more times (3 total, same mode, agreeing values) — confirm the 3rd submission's response has `status: 'updated'` and the UI shows the "fares just updated" message.
5. Search the same route again — confirm the displayed fare for that mode now reflects the updated value.

Expected: all five behaviors match.

- [ ] **Step 5: Commit**

```bash
git add resources/js/app.js
git commit -m "feat: add report-fare UI per leg"
```

---

## Success Criteria (from design spec)

- A rider can report a fare for any leg in under two taps, with no account. — Task 4.
- Three or more agreeing reports within the tolerance window update the live fare without any manual step. — Task 2, verified end-to-end in Task 4 Step 4.
- A single or disagreeing report never changes what other riders see. — Task 2 tests (`test_does_not_update_with_fewer_than_three_reports`, `test_does_not_update_when_reports_disagree_beyond_tolerance`).
- No backend change to `FareEstimator`'s pricing logic itself — only the `fareMode` field is added; the fare lookup/fallback logic is unchanged. — Task 3.
