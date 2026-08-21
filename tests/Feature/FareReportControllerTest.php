<?php

namespace Tests\Feature;

use App\Models\Fare;
use App\Models\FareReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
