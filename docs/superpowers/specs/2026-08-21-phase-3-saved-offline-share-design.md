# Phase 3: Saved Commute, Offline Routes, Share — Design

## Context

Phase 2 shipped ranked, priced itinerary options. Group G (Personal & Offline Tools) is next: zero-cost, on-device features with no new API dependency — the strongest zero-cost differentiator in the roadmap (`aboutus.md`) and buildable without auth, since the app has no login system yet.

## Goal

A user can save a commute (origin/destination pair) for one-tap reuse, revisit their last-searched route while offline, and share a route via a link or the OS share sheet.

## Scope

**In scope:**
- #25 Saved Commute — save/name an origin-destination pair, reload it into the form with one tap.
- #26 Offline Saved Routes — cache the last-fetched `/api/trip-plan` response so a saved commute's most recent result renders without network.
- #27 Share Route — Web Share API (with clipboard-copy fallback) sharing a URL that pre-fills origin/destination.

**Explicitly out of scope for Phase 3:**
- Fare Change Reports (#20), Group E, Group F, Group H, Accessibility Route (#28), Landmark Navigation (#13) — remain deferred per roadmap sequencing.
- Any server-side persistence or accounts — saved commutes are device-local only (no login system exists).

## Architecture

Entirely frontend; no new backend routes or services.

```
Browser
  |
  |-- localStorage: saved commutes [{id, label, origin, destination}]
  |-- IndexedDB (via PWA cache): last /api/trip-plan response per saved commute
  |-- Web Share API / clipboard fallback: shareable URL
  |
  v
trip-planner.blade.php + app.js  (modified: save/load/share UI, offline read path)
```

## Components

**`resources/js/app.js`** (modified)
- `saveCommute(label, origin, destination)` — writes to `localStorage['sakay:commutes']`.
- `loadCommute(id)` — fills form inputs and re-triggers search.
- `cacheTripPlan(commuteId, responseJson)` — stores last successful `/api/trip-plan` response in IndexedDB keyed by commute id.
- On fetch failure (offline), fall back to the cached response for the active commute and render with an "offline result" banner.
- `shareRoute()` — builds `?from=...&to=...` URL, calls `navigator.share()`; if unsupported, copies the URL to clipboard and shows a toast.

**`resources/views/trip-planner.blade.php`** (modified)
- Add a "Save this commute" button next to results, a saved-commutes list in the sidebar, and a share icon button per rendered option.
- On page load, read `from`/`to` query params (from a shared link) and auto-populate the form.

**Service worker / PWA manifest** (new, minimal)
- Register a service worker that caches static assets (existing Vite build) for offline shell load. Trip-plan data caching stays in IndexedDB via app.js, not the service worker cache, since it's keyed per-commute rather than per-URL.

## Data Flow

1. User searches a route (existing Phase 1/2 flow), gets ranked options.
2. User taps "Save this commute" — origin/destination/label written to `localStorage`, current response written to IndexedDB.
3. Later, user opens the app (online or offline): saved commutes render as quick-select chips.
4. Tapping a chip online re-fetches `/api/trip-plan`; tapping offline reads the cached IndexedDB entry and renders it with an "offline result" banner.
5. User taps share on an option: `navigator.share()` opens the OS share sheet with a URL; a recipient opening that URL gets the form pre-filled and a search auto-triggered.

## Error Handling

- **No saved commutes** — sidebar section hidden, no empty-state noise.
- **Offline + no cached result for the selected commute** — existing `status-message` element shows "No offline copy of this route yet — connect and search once to save it."
- **`navigator.share` unsupported** (desktop browsers) — falls back to `navigator.clipboard.writeText` + toast; if clipboard also unavailable, show the URL in a selectable text field.
- **localStorage/IndexedDB unavailable** (private browsing) — save/share buttons stay enabled but show a one-time inline warning instead of silently failing.

## Testing

- **Frontend unit** (Vitest, if configured) or manual: save a commute, reload page, confirm it reappears and reloads the form correctly.
- **Manual offline check**: search a route, save it, go offline (DevTools network throttling), reload commute, confirm cached result renders with the offline banner.
- **Manual share check**: trigger share on mobile (native sheet) and desktop (clipboard fallback); open a shared URL fresh, confirm form auto-fills and search auto-runs.

## Success Criteria

- A saved commute reloads its form fields and last-known result in under one tap, online or offline.
- Sharing a route produces a URL that fully reproduces the search on open, no manual re-entry.
- No backend changes required — everything ships client-side, consistent with the zero-cost design principle.
