<?php

namespace Tests\Feature;

use App\Models\Fare;
use App\Models\FareReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FareReportControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_records_a_valid_report_without_updating_fare_yet(): void
    {
        Fare::create(['mode' => 'jeepney', 'base_fare' => 13.00]);

        $response = $this->postJson('/api/fare-reports', [
            'mode' => 'jeepney',
            'reported_fare' => 15.00,
        ]);

        $response->assertStatus(200)->assertJson([
            'status' => 'recorded',
            'currentFare' => 13.0,
        ]);
        $this->assertDatabaseHas('fare_reports', ['mode' => 'jeepney', 'reported_fare' => 15.00]);
    }

    public function test_reports_completing_corroboration_return_updated_status(): void
    {
        Fare::create(['mode' => 'jeepney', 'base_fare' => 13.00]);
        FareReport::create(['mode' => 'jeepney', 'reported_fare' => 15.00]);
        FareReport::create(['mode' => 'jeepney', 'reported_fare' => 15.00]);

        $response = $this->postJson('/api/fare-reports', [
            'mode' => 'jeepney',
            'reported_fare' => 15.00,
        ]);

        $response->assertStatus(200)->assertJson([
            'status' => 'updated',
            'currentFare' => 15.0,
        ]);
        $this->assertSame(15.0, Fare::where('mode', 'jeepney')->value('base_fare'));
    }

    public function test_rejects_a_mode_not_in_the_fares_table(): void
    {
        $response = $this->postJson('/api/fare-reports', [
            'mode' => 'not-a-real-mode',
            'reported_fare' => 15.00,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['mode']);
    }

    public function test_rejects_a_reported_fare_outside_sanity_bounds(): void
    {
        Fare::create(['mode' => 'jeepney', 'base_fare' => 13.00]);

        $response = $this->postJson('/api/fare-reports', [
            'mode' => 'jeepney',
            'reported_fare' => 500.00,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['reported_fare']);
    }

    public function test_returns_429_after_exceeding_the_rate_limit(): void
    {
        Fare::create(['mode' => 'jeepney', 'base_fare' => 13.00]);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/fare-reports', ['mode' => 'jeepney', 'reported_fare' => 13.00])
                ->assertStatus(200);
        }

        $this->postJson('/api/fare-reports', ['mode' => 'jeepney', 'reported_fare' => 13.00])
            ->assertStatus(429);
    }

    public function test_corroborated_fare_update_is_reflected_in_trip_plan(): void
    {
        Fare::create(['mode' => 'bus', 'base_fare' => 13.00]);

        $this->postJson('/api/fare-reports', ['mode' => 'bus', 'reported_fare' => 16.00])->assertOk();
        $this->postJson('/api/fare-reports', ['mode' => 'bus', 'reported_fare' => 16.00])->assertOk();
        $response = $this->postJson('/api/fare-reports', ['mode' => 'bus', 'reported_fare' => 16.00]);

        $response->assertOk()->assertJson(['status' => 'updated', 'currentFare' => 16.0]);
        $this->assertSame(16.0, Fare::where('mode', 'bus')->value('base_fare'));

        Http::fake([
            '*' => Http::response([
                'data' => [
                    'plan' => [
                        'itineraries' => [
                            [
                                'duration' => 1800,
                                'walkDistance' => 300.0,
                                'legs' => [
                                    ['mode' => 'WALK', 'distance' => 300.0, 'to' => ['name' => 'Stop A']],
                                    ['mode' => 'BUS', 'distance' => 3000.0, 'route' => ['shortName' => 'Bus 1'], 'to' => ['name' => 'Destination']],
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $tripPlanResponse = $this->getJson('/api/trip-plan?from_lat=14.657&from_lon=121.0327&to_lat=14.5578&to_lon=121.0244');

        $tripPlanResponse->assertOk();
        $tripPlanResponse->assertJsonPath('options.0.legs.1.fare', 16);
    }
}
