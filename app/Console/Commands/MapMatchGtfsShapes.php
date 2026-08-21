<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class MapMatchGtfsShapes extends Command
{
    protected $signature = 'gtfs:map-match {--force : Skip the already-matched-input safety check}';

    protected $description = "Map-match GTFS shapes.txt against OTP's street router to produce road-following geometry";

    private const GTFS_ZIP_RELATIVE_PATH = 'otp-data/gtfs-jeepney-bus.zip';

    private const DETOUR_RATIO_THRESHOLD = 2.5;

    private const ALREADY_MATCHED_SPACING_THRESHOLD_METERS = 100.0;

    private const OSM_PBF_RELATIVE_PATH = 'otp-data/metro-manila.osm.pbf';

    private const RAIL_ENDPOINT_SNAP_TOLERANCE_METERS = 5.0;

    /**
     * Both directions of each line reuse the same relation — this GTFS feed
     * models both trip directions with identical shape geometry already, so
     * there's no separate "reverse" shape to source distinctly. Verified by
     * direct extraction (docs/superpowers/specs/2026-08-22-osm-rail-shape-extraction-design.md):
     * each relation's stitched point order already matches the existing GTFS
     * shape's point order, with zero gaps. PNR (881953, 882086) is
     * deliberately absent — no single clean OSM relation covers its extent,
     * so it keeps falling through to the original-points fallback below.
     */
    private const RAIL_SHAPE_TO_OSM_RELATION = [
        '880869' => 8000253, // MRT-3 (Taft Avenue -> North Avenue)
        '882062' => 8000253, // MRT-3 (same physical line; GTFS models both directions identically)
        '882144' => 8000260, // LRT-1 (Dr. Santos -> Fernando Poe Jr.)
        '882188' => 8000260, // LRT-1
        '880814' => 8000264, // LRT-2 (Recto -> Antipolo)
        '882116' => 8000264, // LRT-2
    ];

    private const CAR_MATCH_QUERY = <<<'GRAPHQL'
        query CarMatch($fromLat: Float!, $fromLon: Float!, $toLat: Float!, $toLon: Float!) {
            plan(
                from: { lat: $fromLat, lon: $fromLon }
                to: { lat: $toLat, lon: $toLon }
                transportModes: [{ mode: CAR }]
            ) {
                itineraries {
                    legs {
                        legGeometry { points }
                    }
                }
            }
        }
        GRAPHQL;

    public function handle(): int
    {
        if (! $this->otpIsReachable()) {
            $this->error("OTP isn't running — start it with docker compose up first.");

            return self::FAILURE;
        }

        $zipPath = base_path(self::GTFS_ZIP_RELATIVE_PATH);

        if (! file_exists($zipPath)) {
            $this->error("GTFS zip not found at {$zipPath}");

            return self::FAILURE;
        }

        if (! $this->osmiumIsAvailable()) {
            $this->error('osmium-tool is required for rail shape extraction. Install it: brew install osmium-tool (macOS) or apt-get install osmium-tool (Linux).');

            return self::FAILURE;
        }

        $osmPbfPath = base_path(self::OSM_PBF_RELATIVE_PATH);

        if (! file_exists($osmPbfPath)) {
            $this->error("OSM data not found at {$osmPbfPath}");

            return self::FAILURE;
        }

        $extractedDir = $this->extractGtfs($zipPath);

        if ($extractedDir === null) {
            $this->error("Failed to extract {$zipPath} — it may be corrupt or unreadable.");

            return self::FAILURE;
        }

        $groupedShapes = $this->parseShapes($extractedDir);
        $busShapeIds = $this->classifyBusShapeIds($extractedDir);

        $roadShapes = array_intersect_key($groupedShapes, $busShapeIds);
        $nonRoadShapes = array_diff_key($groupedShapes, $busShapeIds);

        $meanSpacing = $this->meanPointSpacingMeters($roadShapes);

        if ($meanSpacing < self::ALREADY_MATCHED_SPACING_THRESHOLD_METERS && ! $this->option('force')) {
            $this->error(sprintf(
                "shapes.txt's bus/jeepney shapes already look map-matched (mean point spacing %.0fm). Re-running will over-subdivide them. Run otp-data/setup.sh for a pristine feed first, or pass --force.",
                $meanSpacing,
            ));

            return self::FAILURE;
        }

        $this->info('Matching '.count($roadShapes).' bus/jeepney shapes against OTP ('.count($nonRoadShapes)." rail shapes left untouched — CAR-mode street routing doesn't apply to trains)...");

        [$matchedRoadShapes, $stats] = $this->buildMatchedShapes($roadShapes);

        $matchedShapes = $matchedRoadShapes + $nonRoadShapes;

        $this->writeShapesCsv($extractedDir, $matchedShapes);

        $this->printSummary($stats);

        if (! $this->rezip($extractedDir, $zipPath)) {
            $this->error('Failed to rezip the matched shapes — the original GTFS zip was left untouched. Temp files are at: '.$extractedDir);

            return self::FAILURE;
        }

        $this->cleanup($extractedDir);

        $this->info('otp-data/gtfs-jeepney-bus.zip updated. Restart OTP (docker compose up) to rebuild the graph with the new shapes.');

        return self::SUCCESS;
    }

    private function otpIsReachable(): bool
    {
        try {
            $response = Http::timeout(5)->post(config('services.otp.url'), [
                'query' => '{ __typename }',
            ]);
        } catch (ConnectionException) {
            return false;
        }

        return $response->successful();
    }

    private function osmiumIsAvailable(): bool
    {
        exec('which osmium', $output, $exitCode);

        return $exitCode === 0;
    }

    private function extractGtfs(string $zipPath): ?string
    {
        $tempDir = sys_get_temp_dir().'/gtfs-map-match-'.uniqid();
        mkdir($tempDir, recursive: true);

        $zip = new \ZipArchive();

        if ($zip->open($zipPath) !== true) {
            return null;
        }

        $extracted = $zip->extractTo($tempDir);
        $zip->close();

        return $extracted ? $tempDir : null;
    }

    /**
     * @return array<string, list<array{lat: float, lon: float}>> shape_id => ordered points
     */
    private function parseShapes(string $extractedDir): array
    {
        $handle = fopen($extractedDir.'/shapes.txt', 'r');
        $header = fgetcsv($handle, escape: '');
        $idIndex = array_search('shape_id', $header);
        $seqIndex = array_search('shape_pt_sequence', $header);
        $latIndex = array_search('shape_pt_lat', $header);
        $lonIndex = array_search('shape_pt_lon', $header);

        $rows = [];

        while (($row = fgetcsv($handle, escape: '')) !== false) {
            $rows[] = [
                'shape_id' => $row[$idIndex],
                'sequence' => (int) $row[$seqIndex],
                'lat' => (float) $row[$latIndex],
                'lon' => (float) $row[$lonIndex],
            ];
        }

        fclose($handle);

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[$row['shape_id']][] = $row;
        }

        foreach ($grouped as $shapeId => $points) {
            usort($points, fn ($a, $b) => $a['sequence'] <=> $b['sequence']);
            $grouped[$shapeId] = array_map(
                fn ($p) => ['lat' => $p['lat'], 'lon' => $p['lon']],
                $points,
            );
        }

        return $grouped;
    }

    /**
     * CAR-mode street routing only makes sense for shapes that actually run on
     * roads. Rail (route_type 0/1/2 — tram/subway/rail) runs on dedicated
     * track, not streets, so matching it against OTP's CAR router would snap
     * it to nearby roads instead of preserving its real alignment. Only
     * route_type 3 (bus, which this feed also uses for jeepneys) is eligible.
     *
     * @return array<string, true> shape_id => true, for shapes used by a bus/jeepney route
     */
    private function classifyBusShapeIds(string $extractedDir): array
    {
        $routeTypeByRouteId = [];
        $routesHandle = fopen($extractedDir.'/routes.txt', 'r');
        $routesHeader = fgetcsv($routesHandle, escape: '');
        $routeIdIndex = array_search('route_id', $routesHeader);
        $routeTypeIndex = array_search('route_type', $routesHeader);

        while (($row = fgetcsv($routesHandle, escape: '')) !== false) {
            $routeTypeByRouteId[$row[$routeIdIndex]] = $row[$routeTypeIndex];
        }

        fclose($routesHandle);

        $busShapeIds = [];
        $tripsHandle = fopen($extractedDir.'/trips.txt', 'r');
        $tripsHeader = fgetcsv($tripsHandle, escape: '');
        $tripRouteIdIndex = array_search('route_id', $tripsHeader);
        $tripShapeIdIndex = array_search('shape_id', $tripsHeader);

        while (($row = fgetcsv($tripsHandle, escape: '')) !== false) {
            $shapeId = $row[$tripShapeIdIndex] ?? '';
            $routeId = $row[$tripRouteIdIndex] ?? '';

            if ($shapeId !== '' && ($routeTypeByRouteId[$routeId] ?? null) === '3') {
                $busShapeIds[$shapeId] = true;
            }
        }

        fclose($tripsHandle);

        return $busShapeIds;
    }

    /**
     * @return list<array{lat: float, lon: float}>|null null if the relation
     *         couldn't be resolved into a single connected chain (missing
     *         way, gap beyond tolerance, or the osmium subprocess failed)
     */
    private function extractRailShape(string $osmPbfPath, int $relationId): ?array
    {
        $relationPbf = sys_get_temp_dir().'/gtfs-map-match-rail-'.$relationId.'-'.uniqid().'.osm.pbf';
        $relationGeoJson = $relationPbf.'.geojson';

        exec(sprintf(
            'osmium getid -r %s r%d -o %s --overwrite 2>&1',
            escapeshellarg($osmPbfPath),
            $relationId,
            escapeshellarg($relationPbf),
        ), $getidOutput, $getidExitCode);

        if ($getidExitCode !== 0 || ! file_exists($relationPbf)) {
            @unlink($relationPbf);

            return null;
        }

        $opl = shell_exec(sprintf('osmium cat %s -f opl 2>/dev/null', escapeshellarg($relationPbf)));
        $relationLine = null;

        foreach (explode("\n", (string) $opl) as $line) {
            if (str_starts_with($line, 'r')) {
                $relationLine = $line;

                break;
            }
        }

        if ($relationLine === null) {
            @unlink($relationPbf);

            return null;
        }

        $membersPart = substr($relationLine, strrpos($relationLine, ' ') + 1);
        $wayIds = [];

        foreach (explode(',', $membersPart) as $member) {
            if (str_starts_with($member, 'w') && str_ends_with($member, '@')) {
                $wayIds[] = (int) substr($member, 1, -1);
            }
        }

        if (empty($wayIds)) {
            @unlink($relationPbf);

            return null;
        }

        exec(sprintf(
            'osmium export %s -o %s -f geojson -a id,type --overwrite 2>&1',
            escapeshellarg($relationPbf),
            escapeshellarg($relationGeoJson),
        ), $exportOutput, $exportExitCode);

        @unlink($relationPbf);

        if ($exportExitCode !== 0 || ! file_exists($relationGeoJson)) {
            @unlink($relationGeoJson);

            return null;
        }

        $geoJson = json_decode(file_get_contents($relationGeoJson), true);
        @unlink($relationGeoJson);

        $waysById = [];

        foreach ($geoJson['features'] ?? [] as $feature) {
            if (($feature['geometry']['type'] ?? null) === 'LineString') {
                $wayId = $feature['properties']['@id'] ?? null;

                if ($wayId !== null) {
                    $waysById[(int) $wayId] = array_map(
                        fn ($coord) => ['lat' => $coord[1], 'lon' => $coord[0]],
                        $feature['geometry']['coordinates'],
                    );
                }
            }
        }

        return $this->stitchWays($wayIds, $waysById);
    }

    /**
     * @param  list<int>  $orderedWayIds
     * @param  array<int, list<array{lat: float, lon: float}>>  $waysById
     * @return list<array{lat: float, lon: float}>|null
     */
    private function stitchWays(array $orderedWayIds, array $waysById): ?array
    {
        $chain = [];
        $prevEnd = null;

        foreach ($orderedWayIds as $wayId) {
            $coords = $waysById[$wayId] ?? null;

            if ($coords === null) {
                return null;
            }

            if ($prevEnd === null) {
                array_push($chain, ...$coords);
                $prevEnd = $coords[count($coords) - 1];

                continue;
            }

            $first = $coords[0];
            $last = $coords[count($coords) - 1];
            $distToStart = $this->haversineMeters($prevEnd['lat'], $prevEnd['lon'], $first['lat'], $first['lon']);
            $distToEnd = $this->haversineMeters($prevEnd['lat'], $prevEnd['lon'], $last['lat'], $last['lon']);

            if ($distToStart <= $distToEnd) {
                if ($distToStart > self::RAIL_ENDPOINT_SNAP_TOLERANCE_METERS) {
                    return null;
                }

                array_push($chain, ...array_slice($coords, 1));
                $prevEnd = $last;
            } else {
                if ($distToEnd > self::RAIL_ENDPOINT_SNAP_TOLERANCE_METERS) {
                    return null;
                }

                $reversed = array_reverse($coords);
                array_push($chain, ...array_slice($reversed, 1));
                $prevEnd = $reversed[count($reversed) - 1];
            }
        }

        return $chain;
    }

    private function cleanup(string $extractedDir): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($extractedDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }

        rmdir($extractedDir);
    }

    /**
     * @return array{points: list<array{lat: float, lon: float}>, matched: bool, reason: ?string}
     */
    private function matchPair(array $pointA, array $pointB): array
    {
        $otpResult = $this->matchPairViaOtp($pointA['lat'], $pointA['lon'], $pointB['lat'], $pointB['lon']);

        if ($otpResult['points'] === null) {
            return ['points' => [$pointA, $pointB], 'matched' => false, 'reason' => $otpResult['reason']];
        }

        $matched = $otpResult['points'];
        $straightLine = $this->haversineMeters($pointA['lat'], $pointA['lon'], $pointB['lat'], $pointB['lon']);

        if ($straightLine <= 0) {
            return ['points' => [$pointA, $pointB], 'matched' => false, 'reason' => 'degenerate'];
        }

        $roadLength = $this->pathLengthMeters($matched);

        if ($roadLength / $straightLine > self::DETOUR_RATIO_THRESHOLD) {
            return ['points' => [$pointA, $pointB], 'matched' => false, 'reason' => 'detour_ratio'];
        }

        return ['points' => $matched, 'matched' => true, 'reason' => null];
    }

    /**
     * @return array{points: list<array{lat: float, lon: float}>|null, reason: ?string} reason is
     *         'request_failed' (OTP unreachable or errored) or 'unmatchable' (OTP found no route)
     *         when points is null; null reason when points is non-null.
     */
    private function matchPairViaOtp(float $lat1, float $lon1, float $lat2, float $lon2): array
    {
        try {
            $response = Http::timeout(10)->post(config('services.otp.url'), [
                'query' => self::CAR_MATCH_QUERY,
                'variables' => [
                    'fromLat' => $lat1, 'fromLon' => $lon1,
                    'toLat' => $lat2, 'toLon' => $lon2,
                ],
            ]);
        } catch (ConnectionException) {
            return ['points' => null, 'reason' => 'request_failed'];
        }

        if ($response->failed()) {
            return ['points' => null, 'reason' => 'request_failed'];
        }

        $itineraries = $response->json('data.plan.itineraries') ?? [];

        if (empty($itineraries)) {
            return ['points' => null, 'reason' => 'unmatchable'];
        }

        $encoded = $itineraries[0]['legs'][0]['legGeometry']['points'] ?? null;

        if ($encoded === null) {
            return ['points' => null, 'reason' => 'unmatchable'];
        }

        return ['points' => $this->decodePolyline($encoded), 'reason' => null];
    }

    /**
     * Google encoded polyline algorithm (precision 5), matching the decoder in
     * resources/js/app.js — OTP's legGeometry.points uses this same encoding.
     *
     * @return list<array{lat: float, lon: float}>
     */
    private function decodePolyline(string $encoded): array
    {
        $points = [];
        $index = 0;
        $lat = 0;
        $lon = 0;
        $length = strlen($encoded);

        while ($index < $length) {
            $shift = 0;
            $result = 0;

            do {
                $byte = ord($encoded[$index++]) - 63;
                $result |= ($byte & 0x1f) << $shift;
                $shift += 5;
            } while ($byte >= 0x20);

            $lat += ($result & 1) ? ~($result >> 1) : ($result >> 1);

            $shift = 0;
            $result = 0;

            do {
                $byte = ord($encoded[$index++]) - 63;
                $result |= ($byte & 0x1f) << $shift;
                $shift += 5;
            } while ($byte >= 0x20);

            $lon += ($result & 1) ? ~($result >> 1) : ($result >> 1);

            $points[] = ['lat' => $lat / 1e5, 'lon' => $lon / 1e5];
        }

        return $points;
    }

    private function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * @param  list<array{lat: float, lon: float}>  $points
     */
    private function pathLengthMeters(array $points): float
    {
        $total = 0.0;

        for ($i = 0; $i < count($points) - 1; $i++) {
            $total += $this->haversineMeters(
                $points[$i]['lat'], $points[$i]['lon'],
                $points[$i + 1]['lat'], $points[$i + 1]['lon'],
            );
        }

        return $total;
    }

    /**
     * @param  array<string, list<array{lat: float, lon: float}>>  $groupedShapes
     */
    private function meanPointSpacingMeters(array $groupedShapes): float
    {
        $totalLength = 0.0;
        $totalSegments = 0;

        foreach ($groupedShapes as $points) {
            $totalLength += $this->pathLengthMeters($points);
            $totalSegments += max(count($points) - 1, 0);
        }

        return $totalSegments > 0 ? $totalLength / $totalSegments : 0.0;
    }

    /**
     * @param  array<string, list<array{lat: float, lon: float}>>  $groupedShapes
     * @return array{0: array<string, list<array{lat: float, lon: float}>>, 1: array{shapes: int, segments_matched: int, segments_fallback: int, fallback_log: list<array{shape_id: string, segment_index: int, reason: string}>}}
     */
    private function buildMatchedShapes(array $groupedShapes): array
    {
        $matchedShapes = [];
        $stats = ['shapes' => 0, 'segments_matched' => 0, 'segments_fallback' => 0, 'fallback_log' => []];

        foreach ($groupedShapes as $shapeId => $points) {
            $stats['shapes']++;
            $newPoints = [$points[0]];

            for ($i = 0; $i < count($points) - 1; $i++) {
                $result = $this->matchPair($points[$i], $points[$i + 1]);

                $segmentPoints = array_slice($result['points'], 1);
                array_push($newPoints, ...$segmentPoints);

                if ($result['matched']) {
                    $stats['segments_matched']++;
                } else {
                    $stats['segments_fallback']++;
                    $stats['fallback_log'][] = [
                        'shape_id' => $shapeId,
                        'segment_index' => $i,
                        'reason' => $result['reason'],
                    ];
                }
            }

            $matchedShapes[$shapeId] = $newPoints;
        }

        return [$matchedShapes, $stats];
    }

    /**
     * @param  array<string, list<array{lat: float, lon: float}>>  $matchedShapes
     */
    private function writeShapesCsv(string $extractedDir, array $matchedShapes): void
    {
        $handle = fopen($extractedDir.'/shapes.txt', 'w');
        fputcsv($handle, ['shape_id', 'shape_pt_sequence', 'shape_pt_lat', 'shape_pt_lon'], escape: '');

        foreach ($matchedShapes as $shapeId => $points) {
            foreach ($points as $sequence => $point) {
                fputcsv($handle, [$shapeId, $sequence, $point['lat'], $point['lon']], escape: '');
            }
        }

        fclose($handle);
    }

    private function rezip(string $extractedDir, string $originalZipPath): bool
    {
        $tempZipPath = $originalZipPath.'.tmp';

        if (file_exists($tempZipPath)) {
            unlink($tempZipPath);
        }

        $zip = new \ZipArchive();

        if ($zip->open($tempZipPath, \ZipArchive::CREATE) !== true) {
            return false;
        }

        $files = scandir($extractedDir);

        if ($files === false) {
            $zip->close();

            return false;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            if (! $zip->addFile($extractedDir.'/'.$file, $file)) {
                $zip->close();

                return false;
            }
        }

        if (! $zip->close()) {
            return false;
        }

        return rename($tempZipPath, $originalZipPath);
    }

    /**
     * @param  array{shapes: int, segments_matched: int, segments_fallback: int, fallback_log: list<array{shape_id: string, segment_index: int, reason: string}>}  $stats
     */
    private function printSummary(array $stats): void
    {
        $totalSegments = $stats['segments_matched'] + $stats['segments_fallback'];
        $fallbackPercent = $totalSegments > 0
            ? round($stats['segments_fallback'] / $totalSegments * 100, 1)
            : 0.0;

        $this->info("Shapes processed: {$stats['shapes']}");
        $this->info("Segments matched: {$stats['segments_matched']}");
        $this->info("Segments fell back: {$stats['segments_fallback']} ({$fallbackPercent}%)");

        if (! empty($stats['fallback_log'])) {
            $this->warn('Fallback details:');

            foreach ($stats['fallback_log'] as $entry) {
                $this->line("  shape {$entry['shape_id']} segment {$entry['segment_index']}: {$entry['reason']}");
            }
        }
    }
}
