# Phase 1: Working Vertical Slice — Design

## Context

`aboutus.md` organizes Sakay.ph's full scope into 30 features across 8 groups (A–H). The `TESTING-PROJECT` codebase is currently a bare Laravel 12 + Vite scaffold with no custom app logic. This spec defines Phase 1: the first slice of that scope to actually build.

## Goal

A user can open the app, pick an origin and destination, and see a real map with a real route, ETA, and fare estimate — end to end, backed by real Metro Manila transit data. Thin but real: it proves the routing pipeline works before any custom scoring, crowdsourcing, or informal-transit logic gets layered on top.

## Scope

**In scope (minimal slices of Groups A, B, D):**
- Group A: Interactive Map (#1), Route Search (#2), Fastest Route (#3), ETA (#9) — via OpenTripPlanner's own default itinerary ranking.
- Group B: Fare Calculator (#8) — stub fare table, display-only.
- Group D: Pickup Locations (#11) / Drop-off Locations (#12) — satisfied by raw GTFS stop data, no custom stop DB yet.

**Explicitly out of scope for Phase 1:**
- Group C (Fewest Transfers #6, Easiest Route #7, Transfer Instructions #14, Transfer Difficulty #15) — Phase 1 shows whatever itinerary OTP ranks first; custom scoring is Phase 2.
- Group E (all real-time crowdsourced signals) — informal transit (tricycles/jeepneys as a distinct layer) is untouched.
- Group F (historical intelligence) — no trip history exists yet to build statistics from.
- Group G (saved/offline routes, share) — no persistence layer for user data yet.
- Group H (smart recommendation) — depends on outputs from groups not yet built.
- Accessibility Route (#28), Landmark Navigation (#13), fare discounts (student/senior/PWD) — deferred.

## Architecture

Three pieces, browser only ever talks to Laravel:

1. **OpenTripPlanner (OTP2)** — runs as its own Docker container. Ingests the free GTFS feed(s) for Metro Manila (sakayph/gtfs + TUMI feed covering LRT/MRT/LTFRB/PNR/Fort Bus) plus an OSM extract (Geofabrik, Luzon/Metro Manila) to build a routable graph, then serves a local GraphQL API. Not exposed outside the backend network.
2. **Laravel backend** — new endpoint `GET /api/trip-plan?from={lat,lng}&to={lat,lng}`, backed by:
   - `TripPlannerService` — calls OTP's GraphQL API, gets itinerary options (legs, modes, stops, times).
   - `FareEstimator` — walks each leg, matches its transit mode against a stub fare table, sums a total.
   - `TripPlanController` — orchestrates the two, returns one JSON payload: `{ legs, totalFare, totalDuration, walkDistance }`.
3. **Frontend** — Blade view + vanilla JS (matches existing Vite/Tailwind/Axios stack, no framework added). Leaflet + raster OSM tiles for the map (zero setup cost; MapLibre/vector tiles deferred — would look better against `Design_rules.md`'s dark/light tokens but adds a tile-serving dependency not needed yet). Origin/destination pickers, calls `/api/trip-plan` via Axios, draws the route polyline, shows an itinerary list + fare/ETA panel.

## Data

- **Transit graph:** owned entirely by OTP (stops, routes, schedules come from GTFS). No new Laravel tables for transit data in Phase 1.
- **Geographic scope:** full Metro Manila GTFS feed, not filtered to a single corridor — use the real dataset now rather than a synthetic subset.
- **Fare table:** `config/fares.php`, a static PHP config (not a DB table) — flat/simplified per-mode base fares (jeepney, bus, LRT, MRT). It's stub data with no dynamic updates yet, so a config file avoids migration/seeding overhead. Becomes a real `fares` DB table in Phase 2, when Group B needs fare-change reports (#20) and discount toggles.

## Data Flow

1. User picks origin + destination in the browser.
2. Frontend calls `GET /api/trip-plan?from=...&to=...`.
3. `TripPlannerService` sends a GraphQL query to OTP.
4. OTP returns itinerary option(s) — legs, modes, times, stops — using GTFS schedule data.
5. `FareEstimator` prices each leg against `config/fares.php`, sums a total.
6. Laravel returns `{ legs, totalFare, totalDuration, walkDistance }`.
7. Frontend renders the route polyline on the Leaflet map and a fare/ETA summary panel.

## Error Handling

- OTP unreachable or graph not built → Laravel returns HTTP 503 with a clear message; frontend shows "routing unavailable" instead of a blank map.
- No route found between the two points → OTP returns an empty itinerary list → Laravel returns `{ legs: [], error: "no_route" }`; frontend shows a plain "no route found" state.

## Testing

- **OTP graph build:** manual smoke test — query a handful of known Metro Manila origin/destination pairs directly against OTP's GraphQL API before wiring Laravel to it, confirming the GTFS feed produces sane itineraries.
- **Laravel:** feature test hitting `/api/trip-plan` against a stubbed/mocked OTP response (fast, no live OTP needed in CI) — covers fare math and the no-route/error paths.
- **Frontend:** manual browser check — search a real route, confirm the map draws and the fare/ETA panel populates correctly.

## Success Criteria

- OTP graph builds successfully from the full Metro Manila GTFS feed + OSM extract.
- A user can search a real origin/destination pair in the browser and see: a drawn route on the map, a list of legs/modes, total ETA, and a total fare estimate.
- No route found and OTP-down cases degrade gracefully instead of erroring the whole page.
