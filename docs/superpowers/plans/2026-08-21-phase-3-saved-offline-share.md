# Phase 3: Saved Commute, Offline Routes, Share — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a user save a commute (origin/destination pair), reload its last result offline, and share a route via link.

**Architecture:** Pure frontend addition on top of the existing Phase 1/2 vertical slice — no new backend routes, models, or migrations. Two new small ES modules (`savedCommutes.js` for localStorage, `offlineCache.js` for IndexedDB) are wired into the existing `resources/js/app.js`, plus a hand-written service worker for static-asset offline shell.

**Tech Stack:** Vanilla JS (existing stack — no new npm dependency), `localStorage`, `IndexedDB` (native browser API), `navigator.share` / `navigator.clipboard`, a hand-written Service Worker (no vite-plugin-pwa).

## Global Constraints

- No backend changes: no new routes, controllers, models, or migrations. `TripPlanController` and `/api/trip-plan` are untouched.
- The app currently has **no geocoding** — `resources/js/app.js` searches a hardcoded `ORIGIN`/`DESTINATION` lat/lon pair; the visible "From"/"To" text inputs are not wired to the search. Saved commutes and shared links therefore carry **lat/lon pairs**, not free text, until Route Search (#2, already in the roadmap, not in this phase) exists.
- No new npm dependency. No JS test runner exists in this codebase (`package.json` has no test script); per the existing Phase 2 convention ("Frontend — manual browser check"), verification steps in this plan are concrete manual DevTools procedures, not automated test runs.
- Follow existing code style in `app.js`: plain functions, no framework, Tailwind utility classes matching the existing `trip-planner.blade.php` markup (`black/10 dark:border-white/10`, `foreground`/`foreground-secondary` custom color tokens).
- Local dev loop (from `SETUP.md`): `npm run dev` in one terminal, `php artisan serve` in another, app at `http://localhost:8000`. OTP (`docker compose up`) must be running for `findRoute()` to return real results — start it once before beginning manual verification.

---

## File Structure

- `resources/js/savedCommutes.js` — **new**. Pure functions over `localStorage`: list/save/remove saved commutes. No DOM, no fetch — unit-reasoned in isolation, wired into `app.js`.
- `resources/js/offlineCache.js` — **new**. Thin `IndexedDB` wrapper: cache/read a trip-plan JSON response keyed by commute id.
- `resources/js/app.js` — **modified**. Wires the two new modules into the existing search/render flow: save/load a commute, offline fallback on fetch failure, share button per option, URL-param pre-fill on load, service worker registration.
- `resources/views/trip-planner.blade.php` — **modified**. Adds the "save commute" label input + button and the saved-commutes list container to the sidebar.
- `public/sw.js` — **new**. Hand-written service worker: cache-first for same-origin static GETs, network passthrough for `/api/*`.

---

### Task 1: Saved commutes storage module

**Files:**
- Create: `resources/js/savedCommutes.js`
- Modify: `resources/views/trip-planner.blade.php`
- Modify: `resources/js/app.js`

**Interfaces:**
- Produces (from `savedCommutes.js`, consumed by `app.js` in this task and Task 2):
  - `listCommutes(): Array<{id: string, label: string, fromLat: number, fromLon: number, toLat: number, toLon: number}>`
  - `saveCommute({label: string, fromLat: number, fromLon: number, toLat: number, toLon: number}): {id, label, fromLat, fromLon, toLat, toLon}`
  - `removeCommute(id: string): void`

- [ ] **Step 1: Create `savedCommutes.js`**

```js
const STORAGE_KEY = 'sakay:commutes';

function readAll() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        const parsed = raw ? JSON.parse(raw) : [];

        return Array.isArray(parsed) ? parsed : [];
    } catch {
        return [];
    }
}

function writeAll(commutes) {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(commutes));
}

export function listCommutes() {
    return readAll();
}

export function saveCommute({ label, fromLat, fromLon, toLat, toLon }) {
    const commute = {
        id: crypto.randomUUID(),
        label: label && label.trim() ? label.trim() : 'Saved commute',
        fromLat,
        fromLon,
        toLat,
        toLon,
    };

    const commutes = readAll();
    commutes.push(commute);
    writeAll(commutes);

    return commute;
}

export function removeCommute(id) {
    writeAll(readAll().filter((commute) => commute.id !== id));
}
```

- [ ] **Step 2: Verify the module in isolation**

Run: `npm run dev` and `php artisan serve`, open `http://localhost:8000`, open DevTools console, and paste:

```js
const m = await import('/resources/js/savedCommutes.js');
const c = m.saveCommute({ label: 'Test', fromLat: 1, fromLon: 2, toLat: 3, toLon: 4 });
console.log(m.listCommutes());
m.removeCommute(c.id);
console.log(m.listCommutes());
```

Expected: first `listCommutes()` logs an array containing the saved object with a generated `id`; after `removeCommute`, the array no longer contains it. (Import path works because Vite serves `resources/js/` in dev; this is a throwaway console check, not part of the shipped app.)

- [ ] **Step 3: Add sidebar markup for saving and listing commutes**

In `resources/views/trip-planner.blade.php`, insert this block immediately after the closing `</form>` of `#trip-form` and before the `<p id="status-message" ...>` element:

```blade
<div class="flex flex-col gap-2">
    <div class="flex gap-2">
        <input id="commute-label-input" type="text" placeholder="Label (e.g. Home → Work)" autocomplete="off"
            class="flex-1 rounded-lg border border-black/10 dark:border-white/10 bg-transparent px-3 py-2 text-sm outline-none focus:border-foreground/40">
        <button id="save-commute-btn" type="button"
            class="inline-flex items-center justify-center gap-2 rounded-lg border border-black/10 dark:border-white/10 px-3 py-2 text-sm">
            <i data-lucide="bookmark" class="w-4 h-4"></i>
        </button>
    </div>
    <ul id="saved-commutes-list" class="flex flex-col gap-2 text-sm"></ul>
</div>
```

- [ ] **Step 4: Wire save/list/load into `app.js`**

In `resources/js/app.js`:

1. Add the import near the top, alongside the existing imports:

```js
import { listCommutes, saveCommute, removeCommute } from './savedCommutes';
```

2. Add `Bookmark`, `X` to the `lucide` import and `ICONS` map (needed for the saved-commute list and remove button):

```js
import { createIcons, Bus, TrainFront, Footprints, Search, MapPin, Bookmark, X } from 'lucide';

const ICONS = { Bus, TrainFront, Footprints, Search, MapPin, Bookmark, X };
```

3. Change `ORIGIN`/`DESTINATION` from `const` to `let`, since a loaded commute or a shared URL (Task 3) will reassign them:

```js
let ORIGIN = [14.6570, 121.0327]; // SM North EDSA
let DESTINATION = [14.5578, 121.0244]; // Ayala Avenue, Makati
```

4. Add `activeCommuteId` state, near the other top-level `let` declarations (`map, routeLayer`):

```js
let activeCommuteId = null;
```

5. Add the saved-commutes list rendering and load/save logic, placed after `renderOptions` and before `findRoute`:

```js
const savedCommutesListEl = document.getElementById('saved-commutes-list');
const commuteLabelInputEl = document.getElementById('commute-label-input');
const saveCommuteBtnEl = document.getElementById('save-commute-btn');

function renderSavedCommutes() {
    const commutes = listCommutes();
    savedCommutesListEl.innerHTML = '';

    for (const commute of commutes) {
        const li = document.createElement('li');
        li.className = 'flex items-center justify-between gap-2 rounded-lg border border-black/10 dark:border-white/10 px-3 py-2';

        li.innerHTML = `
            <button type="button" data-load-commute class="flex-1 text-left truncate">${commute.label}</button>
            <button type="button" data-remove-commute aria-label="Remove"><i data-lucide="x" class="w-3.5 h-3.5"></i></button>
        `;

        li.querySelector('[data-load-commute]').addEventListener('click', () => loadCommute(commute));
        li.querySelector('[data-remove-commute]').addEventListener('click', () => {
            removeCommute(commute.id);
            renderSavedCommutes();
        });

        savedCommutesListEl.appendChild(li);
    }

    createIcons({ icons: ICONS });
}

function loadCommute(commute) {
    ORIGIN = [commute.fromLat, commute.fromLon];
    DESTINATION = [commute.toLat, commute.toLon];
    activeCommuteId = commute.id;
    findRoute();
}

if (saveCommuteBtnEl) {
    saveCommuteBtnEl.addEventListener('click', () => {
        const commute = saveCommute({
            label: commuteLabelInputEl.value,
            fromLat: ORIGIN[0],
            fromLon: ORIGIN[1],
            toLat: DESTINATION[0],
            toLon: DESTINATION[1],
        });
        activeCommuteId = commute.id;
        commuteLabelInputEl.value = '';
        renderSavedCommutes();
    });
}

renderSavedCommutes();
```

- [ ] **Step 5: Verify save/load/remove in the browser**

With `npm run dev`, `php artisan serve`, and `docker compose up` (OTP) all running, open `http://localhost:8000`:

1. Wait for the default route to load, type a label into the new label input, click the bookmark button.
2. Confirm a row appears in the saved-commutes list showing that label.
3. Reload the page — confirm the saved row persists (backed by `localStorage`).
4. Click the saved row's label — confirm `findRoute()` re-runs (status flips to "Searching…" then results render).
5. Click its `X` — confirm the row disappears and does not reappear on reload.

Expected: all five behaviors match: save creates a row, reload persists it, click reloads the route, remove deletes it permanently.

- [ ] **Step 6: Commit**

```bash
git add resources/js/savedCommutes.js resources/js/app.js resources/views/trip-planner.blade.php
git commit -m "feat: add saved commute list backed by localStorage"
```

---

### Task 2: Offline trip-plan caching (IndexedDB)

**Files:**
- Create: `resources/js/offlineCache.js`
- Modify: `resources/js/app.js`

**Interfaces:**
- Consumes: `activeCommuteId` (module-level state from Task 1), the `data` object returned by `/api/trip-plan` (shape: `{options: [...]}`).
- Produces (from `offlineCache.js`, consumed by `app.js`):
  - `cacheTripPlan(commuteId: string, responseJson: object): Promise<void>`
  - `getCachedTripPlan(commuteId: string): Promise<object|null>`

- [ ] **Step 1: Create `offlineCache.js`**

```js
const DB_NAME = 'sakay-offline';
const DB_VERSION = 1;
const STORE_NAME = 'tripPlans';

function openDb() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, DB_VERSION);

        request.onupgradeneeded = () => {
            if (!request.result.objectStoreNames.contains(STORE_NAME)) {
                request.result.createObjectStore(STORE_NAME);
            }
        };

        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

export async function cacheTripPlan(commuteId, responseJson) {
    const db = await openDb();

    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE_NAME, 'readwrite');
        tx.objectStore(STORE_NAME).put(responseJson, commuteId);
        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);
    });
}

export async function getCachedTripPlan(commuteId) {
    const db = await openDb();

    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE_NAME, 'readonly');
        const request = tx.objectStore(STORE_NAME).get(commuteId);
        request.onsuccess = () => resolve(request.result ?? null);
        request.onerror = () => reject(request.error);
    });
}
```

- [ ] **Step 2: Verify the module in isolation**

In the browser console (same page, dev server running):

```js
const m = await import('/resources/js/offlineCache.js');
await m.cacheTripPlan('test-id', { options: [{ totalFare: 12 }] });
console.log(await m.getCachedTripPlan('test-id'));
console.log(await m.getCachedTripPlan('missing-id'));
```

Expected: first log shows `{options: [{totalFare: 12}]}`; second logs `null`.

- [ ] **Step 3: Wire caching and offline fallback into `findRoute`**

In `resources/js/app.js`, add the import:

```js
import { cacheTripPlan, getCachedTripPlan } from './offlineCache';
```

Replace the body of `findRoute()` with:

```js
async function findRoute() {
    setStatus('Searching…');

    const [fromLat, fromLon] = ORIGIN;
    const [toLat, toLon] = DESTINATION;

    try {
        const params = new URLSearchParams({
            from_lat: fromLat,
            from_lon: fromLon,
            to_lat: toLat,
            to_lon: toLon,
        });

        const response = await fetch(`/api/trip-plan?${params}`);

        if (response.status === 503) {
            setStatus('Routing unavailable — OTP isn’t running yet.');

            return;
        }

        const data = await response.json();

        if (data.error === 'no_route') {
            setStatus('No route found between these points.');

            return;
        }

        if (activeCommuteId) {
            await cacheTripPlan(activeCommuteId, data);
        }

        setStatus(null);
        renderOptions(data.options);
    } catch {
        if (activeCommuteId) {
            const cached = await getCachedTripPlan(activeCommuteId);

            if (cached) {
                setStatus(null);
                renderOptions(cached.options);
                statusEl.textContent = '';
                resultsEl.insertAdjacentHTML('afterbegin', '<p class="text-xs text-foreground-secondary mb-2">Showing offline result — last saved when you were connected.</p>');

                return;
            }

            setStatus('No offline copy of this route yet — connect and search once to save it.');

            return;
        }

        setStatus('Could not reach the server.');
    }
}
```

- [ ] **Step 4: Verify offline fallback in the browser**

1. With OTP, `npm run dev`, and `php artisan serve` running, load a route and save it as a commute (Task 1 flow) — this caches its result via `cacheTripPlan`.
2. Open DevTools → Application → IndexedDB → `sakay-offline` → `tripPlans`, confirm an entry keyed by the commute's id exists.
3. In DevTools → Network, set throttling to "Offline".
4. Click the saved commute's row again.

Expected: the "Showing offline result…" banner appears above the rendered options, and the same options from step 1 render without a network request succeeding. Turn throttling back to "Online" afterward.

- [ ] **Step 5: Commit**

```bash
git add resources/js/offlineCache.js resources/js/app.js
git commit -m "feat: cache trip-plan results in IndexedDB for offline saved commutes"
```

---

### Task 3: Share route via URL

**Files:**
- Modify: `resources/js/app.js`
- Modify: `resources/views/trip-planner.blade.php`

**Interfaces:**
- Consumes: `ORIGIN`, `DESTINATION` (module-level `let` from Task 1).
- Produces: `buildShareUrl(fromLat, fromLon, toLat, toLon): string`, `shareRoute(): Promise<void>` — no other task depends on these.

- [ ] **Step 1: Add a toast element for share feedback**

In `resources/views/trip-planner.blade.php`, add this just before the closing `</body>` tag:

```blade
<p id="toast" class="hidden fixed bottom-4 right-4 rounded-lg border border-black/10 dark:border-white/10 bg-background px-3 py-2 text-sm shadow-lg"></p>
```

- [ ] **Step 2: Add a share button to each rendered option**

In `resources/js/app.js`, inside `renderOptions`, change the option header markup (the `<div class="flex items-center justify-between text-sm">...</div>` block) to include a share button:

```js
li.innerHTML = `
    <div class="flex items-center justify-between text-sm">
        <span class="inline-flex items-center gap-2">
            <span class="text-base font-semibold">₱${Number(option.totalFare).toFixed(2)}</span>
            <span class="text-foreground-secondary">${Math.round(option.totalDuration / 60)} min</span>
        </span>
        <span class="inline-flex items-center gap-2">
            <span class="text-xs rounded-full border border-black/10 dark:border-white/10 px-2 py-0.5">${option.difficulty}</span>
            <button type="button" data-share-btn aria-label="Share this route"><i data-lucide="share-2" class="w-3.5 h-3.5"></i></button>
        </span>
    </div>
    <p class="text-xs text-foreground-secondary">${transferLabel}</p>
    <div data-option-detail class="hidden flex flex-col gap-3 pt-2 border-t border-black/10 dark:border-white/10">
        <ol class="flex flex-col gap-1 text-xs text-foreground-secondary list-decimal list-inside">${instructionsHtml}</ol>
        <ol class="flex flex-col gap-3">${legsHtml}</ol>
    </div>
`;

li.querySelector('[data-share-btn]').addEventListener('click', (event) => {
    event.stopPropagation();
    shareRoute();
});
```

Add `Share2` to the `lucide` import and `ICONS` map:

```js
import { createIcons, Bus, TrainFront, Footprints, Search, MapPin, Bookmark, X, Share2 } from 'lucide';

const ICONS = { Bus, TrainFront, Footprints, Search, MapPin, Bookmark, X, Share2 };
```

- [ ] **Step 3: Implement `buildShareUrl`, `shareRoute`, and toast helper**

Add this in `app.js`, after `loadCommute`:

```js
const toastEl = document.getElementById('toast');

function showToast(message) {
    toastEl.textContent = message;
    toastEl.classList.remove('hidden');
    setTimeout(() => toastEl.classList.add('hidden'), 3000);
}

function buildShareUrl(fromLat, fromLon, toLat, toLon) {
    const url = new URL(window.location.origin + window.location.pathname);
    url.searchParams.set('from_lat', fromLat);
    url.searchParams.set('from_lon', fromLon);
    url.searchParams.set('to_lat', toLat);
    url.searchParams.set('to_lon', toLon);

    return url.toString();
}

async function shareRoute() {
    const url = buildShareUrl(ORIGIN[0], ORIGIN[1], DESTINATION[0], DESTINATION[1]);

    if (navigator.share) {
        try {
            await navigator.share({ url });

            return;
        } catch {
            return;
        }
    }

    if (navigator.clipboard?.writeText) {
        try {
            await navigator.clipboard.writeText(url);
            showToast('Link copied to clipboard');

            return;
        } catch {
            // fall through to manual display
        }
    }

    window.prompt('Copy this link:', url);
}
```

- [ ] **Step 4: Read `from_lat`/`from_lon`/`to_lat`/`to_lon` query params on load**

In `app.js`, immediately before the final `findRoute();` call at the bottom of the file, add:

```js
const urlParams = new URLSearchParams(window.location.search);

if (urlParams.has('from_lat') && urlParams.has('from_lon') && urlParams.has('to_lat') && urlParams.has('to_lon')) {
    ORIGIN = [Number(urlParams.get('from_lat')), Number(urlParams.get('from_lon'))];
    DESTINATION = [Number(urlParams.get('to_lat')), Number(urlParams.get('to_lon'))];
}
```

This must run before `findRoute()` is called, and before `map.setView(ORIGIN, 13)` — move the two `map = L.map(...)` / `map.setView(ORIGIN, 13)` lines' dependency in mind: since `ORIGIN` is read by reference at call time (not at module init), placing this param-parsing block anywhere before the final `findRoute()` call is sufficient; the map's initial `setView(ORIGIN, 13)` at module top will use the default until `drawRoute`'s `fitBounds` runs after the first successful search, so no reordering of the map init block is needed.

- [ ] **Step 5: Verify sharing and link pre-fill in the browser**

1. Load the app, load or leave the default route, click a share icon on any option.
2. On a browser without `navigator.share` (most desktop browsers), confirm the toast "Link copied to clipboard" appears; paste the clipboard content somewhere and confirm it looks like `http://localhost:8000/?from_lat=...&from_lon=...&to_lat=...&to_lon=...`.
3. Open that pasted URL in a new tab.

Expected: the new tab loads, immediately shows "Searching…", then renders the same options as the original search — confirming the query params overrode `ORIGIN`/`DESTINATION` before the initial `findRoute()` call.

- [ ] **Step 6: Commit**

```bash
git add resources/js/app.js resources/views/trip-planner.blade.php
git commit -m "feat: add share-route link with URL pre-fill"
```

---

### Task 4: Offline app-shell service worker

**Files:**
- Create: `public/sw.js`
- Modify: `resources/js/app.js`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: nothing consumed by other tasks — this is a standalone offline-shell layer alongside Task 2's data-level offline cache.

- [ ] **Step 1: Create `public/sw.js`**

```js
const CACHE_NAME = 'sakay-shell-v1';
const SHELL_URLS = ['/'];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll(SHELL_URLS))
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key)))
        )
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    if (event.request.method !== 'GET' || url.origin !== self.location.origin || url.pathname.startsWith('/api/')) {
        return;
    }

    event.respondWith(
        caches.match(event.request).then((cached) => {
            const fetchPromise = fetch(event.request)
                .then((response) => {
                    const clone = response.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(event.request, clone));

                    return response;
                })
                .catch(() => cached);

            return cached ?? fetchPromise;
        })
    );
});
```

- [ ] **Step 2: Register the service worker**

At the end of `resources/js/app.js` (after the URL-param block and before/after the final `findRoute()` call — placement relative to `findRoute()` doesn't matter, registration is fire-and-forget):

```js
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js').catch(() => {});
}
```

- [ ] **Step 3: Verify the service worker registers and serves the shell offline**

1. With `npm run dev` and `php artisan serve` running, load `http://localhost:8000`, open DevTools → Application → Service Workers, confirm `sw.js` shows status "activated and is running".
2. Reload the page once more (so the fetch handler has cached `/` on the prior load).
3. In DevTools → Network, set throttling to "Offline", then hard-reload the page (Cmd+Shift+R on Mac).

Expected: the page shell (layout, form, saved-commutes list) still renders instead of the browser's offline error page; `findRoute()` itself will fail (no network) and either show a saved commute's cached result (Task 2) or "Could not reach the server." Turn throttling back to "Online" afterward.

- [ ] **Step 4: Commit**

```bash
git add public/sw.js resources/js/app.js
git commit -m "feat: add service worker for offline app-shell caching"
```

---

## Success Criteria (from design spec)

- A saved commute reloads its form fields and last-known result in under one tap, online or offline. — Tasks 1 + 2.
- Sharing a route produces a URL that fully reproduces the search on open, no manual re-entry. — Task 3.
- No backend changes required — everything ships client-side. — confirmed: no task touches `app/` or `routes/`.
