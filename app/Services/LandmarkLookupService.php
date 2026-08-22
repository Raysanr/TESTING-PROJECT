<?php

namespace App\Services;

use App\Models\Landmark;

class LandmarkLookupService
{
    private const MAX_DISTANCE_METERS = 100.0;

    private const BOUNDING_BOX_DEGREES = 0.0015;

    /**
     * @return array{name: string, poi_type: string, distance: float}|null
     */
    public function nearest(float $lat, float $lon): ?array
    {
        $candidates = Landmark::query()
            ->whereBetween('lat', [$lat - self::BOUNDING_BOX_DEGREES, $lat + self::BOUNDING_BOX_DEGREES])
            ->whereBetween('lon', [$lon - self::BOUNDING_BOX_DEGREES, $lon + self::BOUNDING_BOX_DEGREES])
            ->get();

        $nearest = null;
        $nearestDistance = null;

        foreach ($candidates as $candidate) {
            $distance = $this->haversineMeters($lat, $lon, $candidate->lat, $candidate->lon);

            if ($distance <= self::MAX_DISTANCE_METERS && ($nearestDistance === null || $distance < $nearestDistance)) {
                $nearest = $candidate;
                $nearestDistance = $distance;
            }
        }

        if ($nearest === null) {
            return null;
        }

        return ['name' => $nearest->name, 'poi_type' => $nearest->poi_type, 'distance' => $nearestDistance];
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
}
