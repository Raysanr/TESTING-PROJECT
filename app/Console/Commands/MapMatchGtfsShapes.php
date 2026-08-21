<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class MapMatchGtfsShapes extends Command
{
    protected $signature = 'gtfs:map-match';

    protected $description = "Map-match GTFS shapes.txt against OTP's street router to produce road-following geometry";

    private const GTFS_ZIP_RELATIVE_PATH = 'otp-data/gtfs-jeepney-bus.zip';

    private const DETOUR_RATIO_THRESHOLD = 2.5;

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

        $extractedDir = $this->extractGtfs($zipPath);
        $groupedShapes = $this->parseShapes($extractedDir);

        $this->info('Matching '.count($groupedShapes).' shapes against OTP...');

        [$matchedShapes, $stats] = $this->buildMatchedShapes($groupedShapes);

        $this->writeShapesCsv($extractedDir, $matchedShapes);

        $this->printSummary($stats);

        $this->info("Matched shapes.txt written to: {$extractedDir}/shapes.txt (not yet applied — original zip untouched)");

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

    private function extractGtfs(string $zipPath): string
    {
        $tempDir = sys_get_temp_dir().'/gtfs-map-match-'.uniqid();
        mkdir($tempDir, recursive: true);

        $zip = new \ZipArchive();
        $zip->open($zipPath);
        $zip->extractTo($tempDir);
        $zip->close();

        return $tempDir;
    }

    /**
     * @return array<string, list<array{lat: float, lon: float}>> shape_id => ordered points
     */
    private function parseShapes(string $extractedDir): array
    {
        $handle = fopen($extractedDir.'/shapes.txt', 'r');
        $header = fgetcsv($handle);
        $idIndex = array_search('shape_id', $header);
        $seqIndex = array_search('shape_pt_sequence', $header);
        $latIndex = array_search('shape_pt_lat', $header);
        $lonIndex = array_search('shape_pt_lon', $header);

        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
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
        $roadLength = $this->pathLengthMeters($matched);

        if ($straightLine > 0 && $roadLength / $straightLine > self::DETOUR_RATIO_THRESHOLD) {
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
        fputcsv($handle, ['shape_id', 'shape_pt_sequence', 'shape_pt_lat', 'shape_pt_lon']);

        foreach ($matchedShapes as $shapeId => $points) {
            foreach ($points as $sequence => $point) {
                fputcsv($handle, [$shapeId, $sequence, $point['lat'], $point['lon']]);
            }
        }

        fclose($handle);
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
