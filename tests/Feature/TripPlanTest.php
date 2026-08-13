<?php

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TripPlanTest extends TestCase
{
    private const ENDPOINT = '/api/trip-plan?from_lat=14.657&from_lon=121.0327&to_lat=14.5578&to_lon=121.0244';

    public function test_returns_priced_itinerary_on_success(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [
                    'plan' => [
                        'itineraries' => [
                            [
                                'duration' => 2520,
                                'walkDistance' => 450.0,
                                'legs' => [
                                    ['mode' => 'WALK', 'distance' => 450.0],
                                    ['mode' => 'BUS', 'distance' => 3000.0],
                                    ['mode' => 'SUBWAY', 'distance' => 8000.0],
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $response = $this->getJson(self::ENDPOINT);

        $response->assertOk()->assertJson([
            'totalFare' => 13.0 + 20.0,
            'totalDuration' => 2520,
            'walkDistance' => 450.0,
        ]);
        $response->assertJsonCount(3, 'legs');
    }

    public function test_returns_no_route_when_otp_finds_nothing(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => ['plan' => ['itineraries' => []]],
            ]),
        ]);

        $response = $this->getJson(self::ENDPOINT);

        $response->assertOk()->assertJson([
            'legs' => [],
            'error' => 'no_route',
        ]);
    }

    public function test_returns_503_when_otp_is_unreachable(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });

        $response = $this->getJson(self::ENDPOINT);

        $response->assertStatus(503)->assertJson([
            'error' => 'otp_unavailable',
        ]);
    }

    public function test_validates_required_coordinates(): void
    {
        $response = $this->getJson('/api/trip-plan');

        $response->assertStatus(422);
    }
}
