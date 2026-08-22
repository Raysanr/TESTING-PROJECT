# Group D: Places & Landmarks — Design

## Context

Groups A-C (routing, fares, transfer scoring) are shipped. `RouteScorer::instructions()` (`app/Services/RouteScorer.php`) already generates step-by-step text like `"Walk to {$to}"` and `"Ride {$route} to {$to}"`, but `{$to}` is whatever OTP's `to.name` field returns — for a WALK leg this is a raw street/intersection vertex name (e.g. "Epifanio de los Santos Avenue / Bulacan Intersection, Quezon City"), not a recognizable landmark. Group D grounds these instructions in real, nameable Metro Manila landmarks pulled from OpenStreetMap.

The original feature request assumed a different stack (OSRM/Valhalla, Turf.js, SQLite Spatialite/PostGIS, live Overpass API queries) than what this project actually runs (OTP for routing, Laravel + SQLite, vanilla PHP, no Node backend). This design adapts the same goal to the real stack, reusing patterns already proven in this codebase's `gtfs:map-match` command (offline `osmium` extraction from the already-present `otp-data/metro-manila.osm.pbf`, no live network dependency).

## Goal

WALK-leg instructions and the first pickup point reference a nearby real landmark (pharmacy, fast food, place of worship, supermarket, or transport hub) when one exists within 100m, instead of only a raw street/intersection name.

## Scope

**In scope:**
- Offline extraction of 7 OSM POI categories (`highway=bus_stop`, `amenity=taxi`, `railway=station`, `amenity=fast_food`, `amenity=pharmacy`, `shop=supermarket`, `amenity=place_of_worship`) from `metro-manila.osm.pbf` into a local `landmarks` table.
- Nearest-landmark lookup (100m radius) via plain PHP + SQLite, no spatial extension.
- Enriching `RouteScorer`'s WALK-leg and first-pickup instruction lines with a landmark suffix.

**Explicitly out of scope for this phase:**
- TODA/jeepney informal flagging-zone modeling (treating street intersections as virtual boarding points) — a separate, more speculative design problem, deferred to a follow-up per explicit decision during brainstorming.
- Any change to transit-stop naming (MRT/LRT/bus stop names from GTFS are already well-named; this only touches WALK-leg endpoints and the first pickup point).
- Live Overpass API integration — extraction is offline-only, from the already-present local PBF.
- A formal wait-penalty adjustment for TODA/taxi terminals in Group C's scoring (the original request's item 4) — this is a scoring-algorithm change independent of landmark data itself, better scoped as its own follow-up once flagging-zone modeling (which it depends on conceptually) is designed.

## Architecture

```
otp-data/metro-manila.osm.pbf
        |
        | php artisan osm:extract-landmarks
        v
osmium tags-filter (7 POI tag combinations)
        |
        v
osmium export -f geojson -a id,type
        |
        v
Parse Point features, skip unnamed nodes, classify poi_type from tags
        |
        v
Landmark::upsert(...) by osm_node_id  ->  landmarks table (SQLite)


Normal trip search:
TripPlannerService's GraphQL query (extended: from/to now also request lat, lon)
        |
        v
RouteScorer::instructions() — for each WALK-leg endpoint and the first pickup point:
        |
        v
LandmarkLookupService::nearest(lat, lon)
        |  bounding-box SQL pre-filter -> exact haversine sort in PHP
        v
  match within 100m?  -> append " (near {name})" to the instruction line
  no match?           -> instruction line unchanged
```

## Components

**`database/migrations/..._create_landmarks_table.php`** (new)
```php
Schema::create('landmarks', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('osm_node_id')->nullable()->unique();
    $table->string('name');
    $table->decimal('lat', 10, 7);
    $table->decimal('lon', 10, 7);
    $table->string('poi_type');
    $table->timestamps();
    $table->index(['lat', 'lon']);
});
```

**`app/Models/Landmark.php`** (new) — plain Eloquent model, `$fillable = ['osm_node_id', 'name', 'lat', 'lon', 'poi_type']`.

**`app/Console/Commands/ExtractOsmLandmarks.php`** (new) — `php artisan osm:extract-landmarks`
- Preflight: `osmium` available, `otp-data/metro-manila.osm.pbf` exists (same pattern/messages as `gtfs:map-match`'s existing checks — can reuse the same style, this command doesn't touch GTFS at all so it's independent).
- Shell `osmium tags-filter <pbf> highway=bus_stop amenity=taxi railway=station amenity=fast_food amenity=pharmacy shop=supermarket amenity=place_of_worship -o <temp>.osm.pbf --overwrite`.
- Shell `osmium export <temp>.osm.pbf -o <temp>.geojson -f geojson -a id,type --overwrite`.
- Parse `Point` features only (all 7 categories are near-universally tagged on OSM nodes, not ways — no way-stitching needed here, unlike rail extraction). Skip any feature with no `name` tag (unnamed nodes aren't useful landmark references). Classify `poi_type` from whichever tag matched (`highway=bus_stop` → `bus_stop`, `amenity=taxi` → `taxi`, `railway=station` → `station`, `amenity=fast_food` → `fast_food`, `amenity=pharmacy` → `pharmacy`, `shop=supermarket` → `supermarket`, `amenity=place_of_worship` → `place_of_worship`).
- Upsert into `landmarks` by `osm_node_id` — naturally idempotent (pure-additive to its own table, never touches the GTFS zip), safe to re-run.
- Print a summary: count per `poi_type`, total upserted.
- Cleans up its own temp files, same discipline as `gtfs:map-match`'s `extractRailShape()`.

**`app/Services/LandmarkLookupService.php`** (new)
```php
class LandmarkLookupService
{
    private const MAX_DISTANCE_METERS = 100.0;
    private const BOUNDING_BOX_DEGREES = 0.0015; // ~165m margin, generous pre-filter

    public function nearest(float $lat, float $lon): ?array
    {
        $candidates = Landmark::query()
            ->whereBetween('lat', [$lat - self::BOUNDING_BOX_DEGREES, $lat + self::BOUNDING_BOX_DEGREES])
            ->whereBetween('lon', [$lon - self::BOUNDING_BOX_DEGREES, $lon + self::BOUNDING_BOX_DEGREES])
            ->get();

        $nearest = null;
        $nearestDistance = null;

        foreach ($candidates as $candidate) {
            $distance = $this->haversineMeters($lat, $lon, (float) $candidate->lat, (float) $candidate->lon);

            if ($distance <= self::MAX_DISTANCE_METERS && ($nearestDistance === null || $distance < $nearestDistance)) {
                $nearest = $candidate;
                $nearestDistance = $distance;
            }
        }

        return $nearest ? ['name' => $nearest->name, 'poi_type' => $nearest->poi_type, 'distance' => $nearestDistance] : null;
    }

    private function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        // same formula already used in App\Console\Commands\MapMatchGtfsShapes
    }
}
```
Bounding-box pre-filter keeps the candidate set small before the exact-distance loop — no spatial extension needed at Metro Manila's scale.

**`app/Services/TripPlannerService.php`** (modified) — extend the GraphQL query's `from`/`to` selections from `{ name }` to `{ name lat lon }`.

**`app/Services/RouteScorer.php`** (modified) — inject `LandmarkLookupService`. In `instructions()`, for each WALK leg, look up `leg.to.lat`/`leg.to.lon`; if a landmark is found within 100m, append `" (near {name})"` to that line. For the first leg overall (the itinerary's pickup point), apply the same enrichment regardless of mode, so the very first instruction also gets landmark context if available.

## Data Flow

1. Developer runs `php artisan osm:extract-landmarks` once (or whenever `metro-manila.osm.pbf` is refreshed) — populates `landmarks`.
2. A normal trip search runs exactly as today, except `TripPlannerService`'s GraphQL query now also requests `lat`/`lon` on `from`/`to`.
3. `RouteScorer::instructions()` calls `LandmarkLookupService::nearest()` for each WALK-leg endpoint and the itinerary's first pickup point.
4. When a match exists within 100m, the instruction line gets a `" (near {name})"` suffix; otherwise it's unchanged from today's output.

## Error Handling

- `osmium` missing / `metro-manila.osm.pbf` missing at extraction time — fail fast, actionable message, nothing written (same pattern as `gtfs:map-match`).
- A leg endpoint with no landmark within 100m — instruction line unchanged; this is the expected common case, not an error.
- Empty `landmarks` table (extraction never run) — `LandmarkLookupService::nearest()` always returns `null`, so the app behaves exactly as it does today. This feature is purely additive and fails safe if never set up.

## Testing

- `osm:extract-landmarks` — same convention as `gtfs:map-match`: no PHPUnit suite, manual run + summary inspection (this project's established convention for `osmium`-shelling CLI tools, since they're one-time/developer-run and not part of any request path).
- `LandmarkLookupService` and `RouteScorer`'s new formatting logic — **these ARE genuinely unit-testable** (pure PHP + DB reads, no subprocess or OTP calls): seed a handful of `Landmark` rows, assert `nearest()` picks the closest one within range and returns `null` beyond it; assert `RouteScorer::instructions()` appends the suffix correctly when a landmark exists and leaves the line unchanged when it doesn't. Matches the existing `FareEstimatorTest`/`RouteScorerTest` coverage pattern.

## Success Criteria

- `php artisan osm:extract-landmarks` against the real `metro-manila.osm.pbf` populates `landmarks` with all 7 POI types, every row having a name.
- A real trip search's WALK-leg instructions show a landmark suffix when one exists nearby, and are unchanged when none does.
- Zero behavior change to bus/rail matching, fare estimation, or any previously-shipped feature.
