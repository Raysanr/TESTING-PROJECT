<?php

namespace Tests\Feature;

use App\Services\TripPlannerService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TripPlannerServiceTest extends TestCase
{
    public function test_requests_multiple_itineraries_from_otp(): void
    {
        Http::fake([
            '*' => Http::response(['data' => ['plan' => ['itineraries' => []]]]),
        ]);

        (new TripPlannerService())->plan(14.657, 121.0327, 14.5578, 121.0244);

        Http::assertSent(function ($request) {
            return $request['variables']['numItineraries'] === 5;
        });
    }

    public function test_requests_lat_and_lon_on_leg_endpoints(): void
    {
        Http::fake([
            '*' => Http::response(['data' => ['plan' => ['itineraries' => []]]]),
        ]);

        (new TripPlannerService())->plan(14.657, 121.0327, 14.5578, 121.0244);

        Http::assertSent(function ($request) {
            $query = $request['query'];

            return str_contains($query, 'from { name lat lon }')
                && str_contains($query, 'to { name lat lon }');
        });
    }
}
