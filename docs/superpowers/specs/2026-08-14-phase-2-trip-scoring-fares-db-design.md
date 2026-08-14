# Phase 2: Trip Scoring + Real Fares — Design

## Context

Phase 1 shipped a working vertical slice: a user can search an origin/destination and see one route, its ETA, and a fare estimate, backed by OTP and a stub `config/fares.php` fare table. The Phase 1 spec explicitly deferred Group C (trip-simplicity scoring) and the fares-DB migration to Phase 2. This spec defines that next slice.

## Goal

A user searching a route sees multiple itinerary options, ranked easiest-first, each with a transfer count, a difficulty label, and plain-language transfer instructions — not just whatever single itinerary OTP happens to rank first. Fares move from a static config file to a real DB table, laying the groundwork for future fare-change reporting without changing today's fare values.

## Scope

**In scope:**
- Group C: Fewest Transfers (#6), Easiest Route (#7), Transfer Instructions (#14), Transfer Difficulty (#15) — via requesting multiple itineraries from OTP and ranking them server-side.
- Group B: migrate `config/fares.php` to a `fares` DB table, seeded from today's values. `FareEstimator`'s pricing logic and outputs are unchanged — only the data source moves.

**Explicitly out of scope for Phase 2:**
- Fare Change Reports (#20) — the actual submission/moderation flow. This spec only builds the DB table that a future phase would need for it.
- Group E (real-time crowdsourced signals), Group F (historical intelligence), Group G (saved/offline/share), Group H (smart recommendation), Accessibility Route (#28), Landmark Navigation (#13), fare discounts — all remain deferred per the roadmap's sequencing (`aboutus.md`).

## Architecture

No new services beyond `RouteScorer`; everything else is Phase 1's existing pipeline widened to handle N itineraries instead of 1.

```
Browser -> GET /api/trip-plan
             |
             v
    TripPlanController
      |
      v
  TripPlannerService          (modified: requests numItineraries from OTP)
      |
      v
  [itinerary A, itinerary B, itinerary C]   <- N itineraries, not 1
      |
      v
   RouteScorer                (NEW: ranks by transfers, walk-distance tiebreaker;
      |                          adds difficulty label + leg instructions)
      v
  [ranked options]
      |
      v
   FareEstimator               (modified: reads from `fares` DB table, not config)
      |
      v
   JSON: { options: [ {legs, totalFare, totalDuration, walkDistance,
                        transferCount, difficulty, instructions}, ... ] }
```

`TripPlanController` currently takes `$itineraries[0]` and returns one flat object. Phase 2 removes that: it loops over all itineraries, runs each through `RouteScorer` then `FareEstimator`, and returns a ranked array under an `options` key. This is a response-shape change to `/api/trip-plan` — the frontend updates to match.

## Components

**`TripPlannerService`** (modified) — `app/Services/TripPlannerService.php`
- Add `numItineraries: Int` to the GraphQL query, pass `5` as a variable in `plan()`.
- Return type unchanged: array of itinerary arrays.

**`RouteScorer`** (new) — `app/Services/RouteScorer.php`
- `rank(array $itineraries): array` — returns the itineraries sorted best-first, each augmented with:
  - `transferCount` — count of legs where `mode !== 'WALK'`, minus 1.
  - `difficulty` — derived from `transferCount`: `0-1 => "Easy"`, `2 => "Moderate"`, `3+ => "Hard"`.
  - `instructions` — plain-language strings built from each leg's `from.name`, `to.name`, `mode`, `route.shortName` (e.g. "Walk to SM North EDSA Terminal", "Ride Jeepney 32 to Quezon Ave", "Transfer to LRT-1 at Roosevelt").
- Sort key: `[transferCount asc, walkDistance asc]`.
- Pure function, no I/O — unit-testable with fixture itineraries, no OTP or DB needed.

**`Fare` model + migration** (new)
- Migration creates `fares` table: `mode` (string, unique), `base_fare` (decimal 8,2), timestamps.
- Seeder inserts the 5 current rows from `config/fares.php` (`jeepney`, `bus`, `lrt`, `mrt`, `default`) with identical values.
- `config/fares.php` is deleted once the migration ships — one source of truth.

**`FareEstimator`** (modified) — `app/Services/FareEstimator.php`
- Same `MODE_MAP` and per-leg logic. Only the lookup changes: `config("fares.{$fareKey}", ...)` becomes a query against the `fares` table, falling back to the `default` row the same way it falls back to `config('fares.default')` today.

**`TripPlanController`** (modified) — `app/Http/Controllers/TripPlanController.php`
- Replace the `$itineraries[0]` line with: rank all itineraries via `RouteScorer`, price each ranked itinerary via `FareEstimator`, return `{ options: [...] }`.

**Frontend** (Blade + vanilla JS, existing Vite stack)
- Result panel changes from one fare/ETA summary to a list of option cards (fare, ETA, transfer count, difficulty badge, instructions). Clicking a card draws that option's polyline on the map — one polyline visible at a time, same as Phase 1.

## Data Flow

1. User picks origin + destination in the browser (unchanged).
2. Frontend calls `GET /api/trip-plan?from_lat=...&from_lon=...&to_lat=...&to_lon=...` (unchanged endpoint/params).
3. `TripPlannerService` sends the GraphQL query to OTP with `numItineraries: 5`.
4. OTP returns up to 5 itineraries.
5. `RouteScorer::rank()` sorts them by transfer count then walk distance, annotating each with `transferCount`, `difficulty`, `instructions`.
6. For each ranked itinerary, `FareEstimator::estimate()` prices its legs against the `fares` table.
7. `TripPlanController` returns `{ options: [ { legs, totalFare, totalDuration, walkDistance, transferCount, difficulty, instructions }, ... ] }`.
8. Frontend renders the ranked list of option cards; selecting one draws its polyline and shows its detail.

## Error Handling

- **OTP unreachable** — unchanged: `TripPlannerService` throws, controller returns HTTP 503 `{ error: "otp_unavailable" }`. `RouteScorer`/`FareEstimator` never run.
- **No route found** — OTP returns an empty itinerary list, controller returns `{ options: [], error: "no_route" }` (renamed from `legs: []`). Frontend's "no route found" state updates its property name to match.
- **Fare lookup miss** — if a leg's mapped mode has no matching row in `fares` (shouldn't happen; the seeder covers every `MODE_MAP` target), `FareEstimator` falls back to the `default` row, same behavior as today's config fallback.
- **DB unseeded** (fresh clone, migration ran but seeder didn't) — `FareEstimator` finds no `default` row either. Treated as a deploy/setup misconfiguration, not a graceful-degrade case: it surfaces as a 500. `SETUP.md` gets a new step: `php artisan db:seed --class=FareSeeder`.

## Testing

- **`RouteScorer`** — unit tests with fixture itinerary arrays of varying transfer counts and walk distances; assert sort order and difficulty-label thresholds. No I/O.
- **`FareEstimator`** — existing feature-test pattern extended: seed a test `fares` table, assert the same fare math as Phase 1's tests, now reading from DB instead of config.
- **`TripPlanController`** — extend the existing stubbed-OTP feature test: mock `TripPlannerService::plan()` to return a fixture with 3 itineraries of varying transfer counts, assert the response's `options` are sorted, priced, and labeled correctly. Also covers `otp_unavailable` and `no_route` paths.
- **Frontend** — manual browser check: search a route with a known multi-option path (one requiring a transfer), confirm the ranked list renders, difficulty labels look sane, and clicking each card redraws the correct polyline.

## Success Criteria

- Searching an origin/destination pair with more than one viable route returns multiple ranked options, not just one.
- Options are sorted easiest-first (fewest transfers, then least walking), each showing a difficulty label and readable transfer instructions.
- Fare values shown are identical to Phase 1's (same numbers, new storage) — the DB migration is a storage swap, not a pricing change.
- OTP-down and no-route cases degrade the same way they did in Phase 1, adapted to the new response shape.
