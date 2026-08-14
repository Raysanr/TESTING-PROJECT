import './bootstrap';
import L from 'leaflet';
import { createIcons, Bus, TrainFront, Footprints, Search, MapPin } from 'lucide';

const ICONS = { Bus, TrainFront, Footprints, Search, MapPin };
createIcons({ icons: ICONS });

const ORIGIN = [14.6570, 121.0327]; // SM North EDSA
const DESTINATION = [14.5578, 121.0244]; // Ayala Avenue, Makati

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

async function findRoute() {
    setStatus('Searching…');

    try {
        const params = new URLSearchParams({
            from_lat: ORIGIN[0],
            from_lon: ORIGIN[1],
            to_lat: DESTINATION[0],
            to_lon: DESTINATION[1],
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

        setStatus(null);
        renderOptions(data.options);
    } catch {
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

findRoute();
