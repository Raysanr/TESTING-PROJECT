# Running Sakay.ph locally (Phase 1)

## 1. Prerequisites

- PHP 8.2+ and Composer
- Node.js and npm
- [Docker Desktop](https://www.docker.com/products/docker-desktop/) — needed to run the routing engine (OpenTripPlanner)
- `osmium-tool` — macOS: `brew install osmium-tool` (used once, to prep map data)

## 2. Get the code

```bash
git clone https://github.com/Raysanr/TESTING-PROJECT.git
cd TESTING-PROJECT
git checkout LINDEN
```

## 3. Install dependencies

```bash
composer install
npm install
```

## 4. Configure the app

```bash
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
```

## 5. Prepare the routing data (one-time, ~5-10 min)

This downloads the transit feed and map data OpenTripPlanner needs, and fixes a couple of known issues in the source data (see `otp-data/README.md` for why).

```bash
cd otp-data && ./setup.sh && cd ..
```

## 6. Start the routing engine

Make sure Docker Desktop is open and running first.

```bash
docker compose up
```

Leave this running in its own terminal. Building the graph takes 1-2 minutes the first time; you'll know it's ready when you see `Grizzly server running` in the log. Routing will be served on `http://localhost:8080`.

## 7. Start the app

In a separate terminal:

```bash
npm run dev
```

And another one:

```bash
php artisan serve
```

## 8. Open it

Visit **http://localhost:8000** — search a route (e.g. "SM North EDSA" to "Ayala Avenue, Makati") and you should see a real map, route, ETA, and fare estimate.

## Troubleshooting

- **"Routing unavailable" in the app** — `docker compose up` isn't running, or the graph is still building. Check its terminal.
- **Docker build fails with `OutOfMemoryError`** — Docker Desktop's memory allocation is too low. Docker Desktop → Settings → Resources → Memory, raise it to at least 6GB.
- **`no trips within the configured transit service period`** — the GTFS calendar patch in `otp-data/setup.sh` didn't apply; re-run `./setup.sh` from `otp-data/`.
- **Only jeepney/bus/MRT-3 route, no LRT-1/2 or PNR** — expected; see `otp-data/README.md` for the manual step to add that feed.
