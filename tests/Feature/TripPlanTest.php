<?php

namespace Tests\Feature;

use Database\Seeders\FareSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TripPlanTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/trip-plan?from_lat=14.657&from_lon=121.0327&to_lat=14.5578&to_lon=121.0244';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FareSeeder::class);
    }

    public function test_returns_ranked_priced_options_on_success(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [
                    'plan' => [
                        'itineraries' => [
                            [
                                'duration' => 3600,
                                'walkDistance' => 900.0,
                                'legs' => [
                                    ['mode' => 'WALK', 'distance' => 300.0, 'to' => ['name' => 'Stop A']],
                                    ['mode' => 'BUS', 'distance' => 3000.0, 'route' => ['shortName' => 'Bus 1'], 'to' => ['name' => 'Stop B']],
                                    ['mode' => 'SUBWAY', 'distance' => 8000.0, 'route' => ['shortName' => 'MRT-3'], 'to' => ['name' => 'Stop C']],
                                    ['mode' => 'BUS', 'distance' => 2000.0, 'route' => ['shortName' => 'Bus 2'], 'to' => ['name' => 'Destination']],
                                ],
                            ],
                            [
                                'duration' => 2520,
                                'walkDistance' => 450.0,
                                'legs' => [
                                    ['mode' => 'WALK', 'distance' => 450.0, 'to' => ['name' => 'Stop A']],
                                    ['mode' => 'BUS', 'distance' => 3000.0, 'route' => ['shortName' => 'Bus 1'], 'to' => ['name' => 'Destination']],
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $response = $this->getJson(self::ENDPOINT);

        $response->assertOk();
        $response->assertJsonCount(2, 'options');

        $response->assertJsonPath('options.0.transferCount', 0);
        $response->assertJsonPath('options.0.totalFare', 13);
        $response->assertJsonPath('options.0.difficulty', 'Easy');
        $response->assertJsonPath('options.1.transferCount', 2);
        $response->assertJsonPath('options.1.totalFare', 46);
        $response->assertJsonPath('options.1.difficulty', 'Moderate');
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
            'options' => [],
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
