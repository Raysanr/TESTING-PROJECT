<?php

namespace App\Console\Commands;

use App\Models\Landmark;
use Illuminate\Console\Command;

class ExtractOsmLandmarks extends Command
{
    protected $signature = 'osm:extract-landmarks';

    protected $description = 'Extract named POIs (transport hubs, pharmacies, fast food, supermarkets, places of worship) from the local OSM data into the landmarks table';

    private const OSM_PBF_RELATIVE_PATH = 'otp-data/metro-manila.osm.pbf';

    private const POI_TAG_FILTERS = [
        'highway=bus_stop' => 'bus_stop',
        'amenity=taxi' => 'taxi',
        'railway=station' => 'station',
        'amenity=fast_food' => 'fast_food',
        'amenity=pharmacy' => 'pharmacy',
        'shop=supermarket' => 'supermarket',
        'amenity=place_of_worship' => 'place_of_worship',
    ];

    public function handle(): int
    {
        exec('command -v osmium', $output, $exitCode);

        if ($exitCode !== 0) {
            $this->error('osmium-tool is required. Install it: brew install osmium-tool (macOS) or apt-get install osmium-tool (Linux).');

            return self::FAILURE;
        }

        $osmPbfPath = base_path(self::OSM_PBF_RELATIVE_PATH);

        if (! file_exists($osmPbfPath)) {
            $this->error("OSM data not found at {$osmPbfPath}");

            return self::FAILURE;
        }

        $filteredPbf = sys_get_temp_dir().'/osm-extract-landmarks-'.uniqid().'.osm.pbf';
        $geoJsonPath = $filteredPbf.'.geojson';

        $tagArgs = implode(' ', array_map('escapeshellarg', array_keys(self::POI_TAG_FILTERS)));

        exec(sprintf(
            'osmium tags-filter %s %s -o %s --overwrite 2>&1',
            escapeshellarg($osmPbfPath),
            $tagArgs,
            escapeshellarg($filteredPbf),
        ), $filterOutput, $filterExitCode);

        if ($filterExitCode !== 0 || ! file_exists($filteredPbf)) {
            $this->error('Failed to filter OSM data for landmark tags.');
            @unlink($filteredPbf);

            return self::FAILURE;
        }

        exec(sprintf(
            'osmium export %s -o %s -f geojson -a id,type --overwrite 2>&1',
            escapeshellarg($filteredPbf),
            escapeshellarg($geoJsonPath),
        ), $exportOutput, $exportExitCode);

        @unlink($filteredPbf);

        if ($exportExitCode !== 0 || ! file_exists($geoJsonPath)) {
            $this->error('Failed to export filtered OSM data to GeoJSON.');
            @unlink($geoJsonPath);

            return self::FAILURE;
        }

        $geoJson = json_decode(file_get_contents($geoJsonPath), true);
        @unlink($geoJsonPath);

        $countsByType = array_fill_keys(array_values(self::POI_TAG_FILTERS), 0);
        $skippedUnnamed = 0;

        foreach ($geoJson['features'] ?? [] as $feature) {
            if (($feature['geometry']['type'] ?? null) !== 'Point') {
                continue;
            }

            $properties = $feature['properties'] ?? [];
            $name = $properties['name'] ?? null;

            if ($name === null) {
                $skippedUnnamed++;

                continue;
            }

            $poiType = $this->classifyPoiType($properties);

            if ($poiType === null) {
                continue;
            }

            $osmId = $properties['@id'] ?? null;
            $coordinates = $feature['geometry']['coordinates'];

            Landmark::updateOrCreate(
                ['osm_node_id' => $osmId],
                ['name' => $name, 'lat' => $coordinates[1], 'lon' => $coordinates[0], 'poi_type' => $poiType],
            );

            $countsByType[$poiType]++;
        }

        $this->info('Landmarks extracted:');

        foreach ($countsByType as $type => $count) {
            $this->info("  {$type}: {$count}");
        }

        $this->info('Skipped (unnamed): '.$skippedUnnamed);
        $this->info('Total upserted: '.array_sum($countsByType));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function classifyPoiType(array $properties): ?string
    {
        foreach (self::POI_TAG_FILTERS as $tag => $poiType) {
            [$key, $value] = explode('=', $tag);

            if (($properties[$key] ?? null) === $value) {
                return $poiType;
            }
        }

        return null;
    }
}
