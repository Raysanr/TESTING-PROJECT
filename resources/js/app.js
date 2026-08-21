import './bootstrap';
import L from 'leaflet';
import { createIcons, Bus, TrainFront, Footprints, Search, MapPin, Bookmark, X, Share2 } from 'lucide';
import { listCommutes, saveCommute, removeCommute } from './savedCommutes';
import { cacheTripPlan, getCachedTripPlan } from './offlineCache';

const ICONS = { Bus, TrainFront, Footprints, Search, MapPin, Bookmark, X, Share2 };
createIcons({ icons: ICONS });

let ORIGIN = [14.6570, 121.0327]; // SM North EDSA
let DESTINATION = [14.5578, 121.0244]; // Ayala Avenue, Makati

// Google encoded polyline algorithm (precision 5), as returned by OTP's legGeometry.points.
function decodePolyline(encoded) {
    const points = [];
    let index = 0;
    let lat = 0;
    let lon = 0;

    while (index < encoded.length) {
        let shift = 0;
        let result = 0;
        let byte;

        do {
            byte = encoded.charCodeAt(index++) - 63;
            result |= (byte & 0x1f) << shift;
            shift += 5;
        } while (byte >= 0x20);
        lat += result & 1 ? ~(result >> 1) : result >> 1;

        shift = 0;
        result = 0;

        do {
            byte = encoded.charCodeAt(index++) - 63;
            result |= (byte & 0x1f) << shift;
            shift += 5;
        } while (byte >= 0x20);
        lon += result & 1 ? ~(result >> 1) : result >> 1;

        points.push([lat / 1e5, lon / 1e5]);
    }

    return points;
}

const mapEl = document.getElementById('map');
let map, routeLayer;
let activeCommuteId = null;

if (mapEl) {
    map = L.map(mapEl, { zoomControl: true }).setView(ORIGIN, 13);

    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors',
        maxZoom: 19,
    }).addTo(map);

    routeLayer = L.layerGroup().addTo(map);
    map.setView(ORIGIN, 13);
}

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

        li.addEventListener('click', () => selectOption(option, li));
        optionsListEl.appendChild(li);

        if (index === 0) {
            selectOption(option, li);
        }
    });

    createIcons({ icons: ICONS });
}

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
            <button type="button" data-load-commute class="flex-1 text-left truncate"></button>
            <button type="button" data-remove-commute aria-label="Remove"><i data-lucide="x" class="w-3.5 h-3.5"></i></button>
        `;

        const loadBtn = li.querySelector('[data-load-commute]');
        loadBtn.textContent = commute.label;
        loadBtn.addEventListener('click', () => loadCommute(commute));
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
            try {
                await cacheTripPlan(activeCommuteId, data);
            } catch {
                // caching is best-effort; don't let it block rendering a successful result
            }
        }

        setStatus(null);
        renderOptions(data.options);
    } catch {
        if (activeCommuteId) {
            const cached = await getCachedTripPlan(activeCommuteId);

            if (cached && Array.isArray(cached.options)) {
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

const form = document.getElementById('trip-form');
if (form) {
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        findRoute();
    });
}

const urlParams = new URLSearchParams(window.location.search);

if (urlParams.has('from_lat') && urlParams.has('from_lon') && urlParams.has('to_lat') && urlParams.has('to_lon')) {
    ORIGIN = [Number(urlParams.get('from_lat')), Number(urlParams.get('from_lon'))];
    DESTINATION = [Number(urlParams.get('to_lat')), Number(urlParams.get('to_lon'))];
}

findRoute();
