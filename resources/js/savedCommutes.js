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
