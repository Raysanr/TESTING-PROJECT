<?php

namespace Tests\Feature;

use App\Models\FareReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FareReportModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_fare_report_with_expected_defaults(): void
    {
        $report = FareReport::create([
            'mode' => 'jeepney',
            'reported_fare' => 14.00,
        ]);

        $this->assertSame('jeepney', $report->mode);
        $this->assertSame(14.0, $report->reported_fare);
        $this->assertFalse((bool) $report->applied);
        $this->assertNull($report->client_hash);
        $this->assertNotNull($report->created_at);
    }
}
