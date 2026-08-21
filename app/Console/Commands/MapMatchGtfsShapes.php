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

        $totalPoints = array_sum(array_map('count', $groupedShapes));

        $this->info('Shapes found: '.count($groupedShapes));
        $this->info("Total points across all shapes: {$totalPoints}");

        $this->cleanup($extractedDir);

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
}
