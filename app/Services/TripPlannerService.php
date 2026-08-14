<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TripPlannerService
{
    private const NUM_ITINERARIES = 5;

    private const QUERY = <<<'GRAPHQL'
        query TripPlan($fromLat: Float!, $fromLon: Float!, $toLat: Float!, $toLon: Float!, $numItineraries: Int!) {
            plan(
                from: { lat: $fromLat, lon: $fromLon }
                to: { lat: $toLat, lon: $toLon }
                transportModes: [{ mode: WALK }, { mode: TRANSIT }]
                numItineraries: $numItineraries
            ) {
                itineraries {
                    duration
                    walkDistance
                    legs {
                        mode
                        startTime
                        endTime
                        distance
                        from { name }
                        to { name }
                        route { shortName longName }
                        legGeometry { points }
                    }
                }
            }
        }
        GRAPHQL;

    /**
     * @return array<int, array<string, mixed>> itineraries, empty when no route was found
     *
     * @throws RuntimeException when OTP is unreachable or returns an error
     */
    public function plan(float $fromLat, float $fromLon, float $toLat, float $toLon): array
    {
        try {
            $response = Http::timeout(10)->post(config('services.otp.url'), [
                'query' => self::QUERY,
                'variables' => [
                    'fromLat' => $fromLat,
                    'fromLon' => $fromLon,
                    'toLat' => $toLat,
                    'toLon' => $toLon,
                    'numItineraries' => self::NUM_ITINERARIES,
                ],
            ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('OTP unreachable: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw new RuntimeException('OTP request failed: '.$response->status());
        }

        $body = $response->json();

        if (isset($body['errors'])) {
            throw new RuntimeException('OTP returned errors: '.json_encode($body['errors']));
        }

        return $body['data']['plan']['itineraries'] ?? [];
    }
}
