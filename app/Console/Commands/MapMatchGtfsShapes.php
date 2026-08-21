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

        $this->info('OTP is reachable and the GTFS zip exists.');

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
}
