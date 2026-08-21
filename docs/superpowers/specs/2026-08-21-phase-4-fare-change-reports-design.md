# Phase 4: Fare Change Reports — Design

## Context

Phase 2 moved fares from `config/fares.php` into a `fares` DB table (`mode` unique, `base_fare`) specifically to lay groundwork for this feature without changing today's values. Group B's last unbuilt feature — Fare Change Reports (#20) — lets riders report a fare that no longer matches what the app shows. The app has no accounts/auth, so this can't be a moderation-queue-plus-admin-login flow; it has to self-moderate from the reports alone.

## Goal

A rider who pays a different fare than the app quoted can report the real fare for that mode in one tap. When enough independent reports agree, the `fares` table updates itself automatically — no admin, no login, no manual approval step.

## Scope

**In scope:**
- `fare_reports` table logging every submission (mode, reported fare, timestamp).
- `POST /api/fare-reports` — validates and stores a report.
- Auto-corroboration: when a mode accumulates enough recent agreeing reports, `fares.base_fare` updates automatically.
- A "Report a different fare" affordance per leg in the results UI.

**Explicitly out of scope for Phase 4:**
- Any accounts/auth, admin dashboard, or manual moderation UI — none exist in this app and none are being added.
- Fare discounts (student/senior/PWD) — separate, unscoped feature.
- Group D, E, F, H, Accessibility Route (#28) — remain deferred per `aboutus.md`'s sequencing.

## Core Constraint

**Self-moderation only, no auth.** Anyone can call the endpoint anonymously (matches the rest of the app — no login exists). The abuse-resistance has to come from the corroboration rule itself, not from identity: a single report never changes a fare; only *agreement across several independent-looking reports* does.

## Architecture

```
Browser
  |
  | POST /api/fare-reports { mode, reported_fare }
  v
FareReportController@store
  |
  v
FareReport::create(...)          (always logged, regardless of outcome)
  |
  v
FareCorroborationService::evaluate(mode)   (NEW — runs synchronously after each insert)
  |
  | if >= threshold recent reports for `mode` agree within tolerance:
  v
Fare::where('mode', $mode)->update(['base_fare' => $agreedValue])
  |
  v
JSON: { status: 'recorded' | 'updated', currentFare: float }
```

No queue, no scheduled job — corroboration is checked inline on each submission, since report volume is expected to be low (this is the same "handful of active reporters" reality the roadmap's passive-over-active principle describes for Group E, even though Group B itself doesn't use passive inference).

## Components

**`fare_reports` table + migration** (new)
- `id`, `mode` (string, indexed), `reported_fare` (decimal 8,2), `client_hash` (string, nullable — see Abuse Resistance), `applied` (boolean, default false), `created_at` (no `updated_at`; reports are immutable).

**`FareReport` model** (new) — `app/Models/FareReport.php`
- `$fillable = ['mode', 'reported_fare', 'client_hash']`.
- No relationships needed; `mode` is a loose string reference to `fares.mode`, not a foreign key, so a report for a not-yet-seeded mode still records (fails closed on corroboration, not on logging).

**`FareCorroborationService`** (new) — `app/Services/FareCorroborationService.php`
- `evaluate(string $mode): ?float` — pure-ish (one read query, one conditional write), unit-testable against a seeded `fare_reports` table.
- Rule: pull unapplied `FareReport` rows for `$mode` from the last 14 days. If there are **at least 3**, and at least 3 of them fall within **±₱1.00** of each other, compute their average, update `fares.base_fare` to that average, mark those specific reports `applied = true`, and return the new fare. Otherwise return `null`.
- Constants (`MIN_CORROBORATING_REPORTS = 3`, `TOLERANCE = 1.00`, `WINDOW_DAYS = 14`) as named class constants, not magic numbers.

**`FareReportController`** (new) — `app/Http/Controllers/FareReportController.php`
- `store(Request $request): JsonResponse`
  - Validates: `mode` required, string, must exist in `fares.mode` (`Rule::exists('fares', 'mode')`) — so a report can only target a mode the app already prices, preventing junk-mode spam.
  - Validates: `reported_fare` required, numeric, between 1 and 200 (sanity bound against a fat-fingered or malicious value skewing the average before corroboration even matters).
  - Creates the `FareReport` (storing a `client_hash` — see below).
  - Calls `FareCorroborationService::evaluate($mode)`.
  - Returns `{ status: 'updated', currentFare: $new }` if corroboration fired, else `{ status: 'recorded', currentFare: <current fares.base_fare for mode> }`.

**`routes/api.php`** (modified)
- Add `Route::post('/fare-reports', [FareReportController::class, 'store']);`.
- Rate limit this route specifically (`throttle:10,1` — 10 requests/minute per IP, via Laravel's default IP-keyed rate limiter) since it's unauthenticated and writes.

**Frontend** (`resources/js/app.js`, `resources/views/trip-planner.blade.php`)
- Each leg line in a rendered option (`legLine()` in `app.js`) gets a small "Report fare" link next to its fare label, visible only on legs where `leg.fare > 0`.
- Clicking it reveals an inline number input (pre-filled with the currently shown fare) and a submit button, replacing the link — no modal, no new page.
- On submit: `POST /api/fare-reports` with `{ mode: <leg's mapped mode>, reported_fare: <value> }`. The mode string sent must match what `FareEstimator`'s `MODE_MAP` resolves the leg to server-side — the frontend doesn't have that map, so the **leg's fare-mode is added to the `/api/trip-plan` response** (new field `leg.fareMode`, populated by `FareEstimator::estimate()` alongside the existing `fare` field) so the frontend can echo it back verbatim rather than re-deriving it.
- On success: replace the input with a short "Thanks — reported." confirmation (and, if `status === 'updated'`, "Fares just updated to ₱X.XX" so the reporter sees their corroboration landed).

## Abuse Resistance (informal, matches app's no-auth reality)

- **No fare-value trust until corroboration.** A single malicious report does nothing by itself.
- **Coordinated spam is still possible** (someone submits 3+ agreeing fake reports) — accepted risk for a zero-cost MVP with no accounts; documented here rather than solved. A `client_hash` column (a non-identifying hash of IP + User-Agent, stored but not exposed) is captured now so a future phase could add "at most one counted report per client per mode per day" without a schema change — **not enforced in Phase 4**, just the column exists for that follow-up.
- **Rate limiting** (`throttle:10,1`) bounds raw request volume regardless of content.

## Data Flow

1. Rider sees a leg's fare in a rendered option, taps "Report fare," types the fare they actually paid, submits.
2. `POST /api/fare-reports` validates `mode` against the `fares` table and `reported_fare` against the sanity bounds.
3. `FareReportController` logs the report unconditionally, then asks `FareCorroborationService` to check for corroboration on that mode.
4. If ≥3 unapplied reports from the last 14 days agree within ±₱1.00, `fares.base_fare` updates to their average and those reports are marked `applied`; otherwise nothing changes.
5. Response tells the frontend whether the fare just updated; the UI confirms receipt either way.
6. The *next* `/api/trip-plan` search (this one or a later one) reads the current `fares` table via the existing `FareEstimator`, so an updated fare is reflected automatically — no separate propagation step needed, since Phase 2 already made `fares` the single source of truth.

## Error Handling

- **`mode` not in `fares` table** — 422 validation error, `{ errors: { mode: [...] } }`, standard Laravel validation response. Frontend shows a generic "Couldn't submit that report" message (this should be unreachable in practice since the frontend only ever sends modes the backend just priced).
- **`reported_fare` out of sanity bounds** — 422 validation error, same handling.
- **Rate limit exceeded** — Laravel's default 429 response; frontend shows "Too many reports — try again in a minute."
- **Corroboration service failure** (e.g., DB error mid-update) — the report row is still committed (it's a separate statement before the service runs); the corroboration check/update runs inside a DB transaction so a failure there rolls back only the fare-update attempt, not the logged report. Response falls back to `{ status: 'recorded', currentFare: <unchanged> }`.

## Testing

- **`FareCorroborationService`** — feature tests seeding `fare_reports` directly: fewer than 3 reports → no update; 3+ reports outside tolerance → no update; 3+ reports within tolerance → `fares.base_fare` updates to their average and exactly those reports are marked `applied`; reports older than 14 days are excluded from the count.
- **`FareReportController`** — feature tests: valid report for a seeded mode returns `recorded`; a report that completes corroboration returns `updated` with the new fare and a subsequent `/api/trip-plan` call reflects it; invalid `mode` returns 422; `reported_fare` outside bounds returns 422; 11th request in a minute from the same IP returns 429.
- **Frontend** — manual browser check (same convention as Phases 1-3, no JS test runner in this repo): report a fare on a rendered leg, confirm the inline form appears and the confirmation replaces it on success.

## Success Criteria

- A rider can report a fare for any leg in under two taps, with no account.
- Three or more agreeing reports within the tolerance window update the live fare without any manual step.
- A single or disagreeing report never changes what other riders see.
- No backend change to `FareEstimator`'s pricing logic itself — Phase 4 only changes where `fares.base_fare` values come from, exactly as Phase 2's migration was designed to allow.
