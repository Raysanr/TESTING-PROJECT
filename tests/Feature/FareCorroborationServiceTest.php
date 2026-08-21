<?php

namespace Tests\Feature;

use App\Models\Fare;
use App\Models\FareReport;
use App\Services\FareCorroborationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FareCorroborationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_does_not_update_with_fewer_than_three_reports(): void
    {
        Fare::create(['mode' => 'jeepney', 'base_fare' => 13.00]);
        FareReport::create(['mode' => 'jeepney', 'reported_fare' => 15.00]);
        FareReport::create(['mode' => 'jeepney', 'reported_fare' => 15.00]);

        $result = (new FareCorroborationService())->evaluate('jeepney');

        $this->assertNull($result);
        $this->assertSame(13.0, Fare::where('mode', 'jeepney')->value('base_fare'));
    }

    public function test_does_not_update_when_reports_disagree_beyond_tolerance(): void
    {
        Fare::create(['mode' => 'jeepney', 'base_fare' => 13.00]);
        FareReport::create(['mode' => 'jeepney', 'reported_fare' => 15.00]);
        FareReport::create(['mode' => 'jeepney', 'reported_fare' => 18.00]);
        FareReport::create(['mode' => 'jeepney', 'reported_fare' => 22.00]);

        $result = (new FareCorroborationService())->evaluate('jeepney');

        $this->assertNull($result);
        $this->assertSame(13.0, Fare::where('mode', 'jeepney')->value('base_fare'));
    }

    public function test_updates_fare_when_three_reports_agree_within_tolerance(): void
    {
        Fare::create(['mode' => 'jeepney', 'base_fare' => 13.00]);
        $r1 = FareReport::create(['mode' => 'jeepney', 'reported_fare' => 15.00]);
        $r2 = FareReport::create(['mode' => 'jeepney', 'reported_fare' => 15.50]);
        $r3 = FareReport::create(['mode' => 'jeepney', 'reported_fare' => 14.50]);

        $result = (new FareCorroborationService())->evaluate('jeepney');

        $this->assertSame(15.0, $result);
        $this->assertSame(15.0, Fare::where('mode', 'jeepney')->value('base_fare'));
        $this->assertTrue($r1->fresh()->applied);
        $this->assertTrue($r2->fresh()->applied);
        $this->assertTrue($r3->fresh()->applied);
    }

    public function test_ignores_reports_older_than_the_window(): void
    {
        Fare::create(['mode' => 'jeepney', 'base_fare' => 13.00]);

        FareReport::create(['mode' => 'jeepney', 'reported_fare' => 15.00, 'created_at' => now()->subDays(20)]);
        FareReport::create(['mode' => 'jeepney', 'reported_fare' => 15.00, 'created_at' => now()->subDays(20)]);
        FareReport::create(['mode' => 'jeepney', 'reported_fare' => 15.00, 'created_at' => now()->subDays(20)]);

        $result = (new FareCorroborationService())->evaluate('jeepney');

        $this->assertNull($result);
        $this->assertSame(13.0, Fare::where('mode', 'jeepney')->value('base_fare'));
    }

    public function test_ignores_already_applied_reports_when_counting(): void
    {
        Fare::create(['mode' => 'jeepney', 'base_fare' => 13.00]);

        FareReport::create(['mode' => 'jeepney', 'reported_fare' => 15.00, 'applied' => true]);
        FareReport::create(['mode' => 'jeepney', 'reported_fare' => 15.00, 'applied' => true]);
        FareReport::create(['mode' => 'jeepney', 'reported_fare' => 15.00, 'applied' => true]);
        FareReport::create(['mode' => 'jeepney', 'reported_fare' => 15.00]);

        $result = (new FareCorroborationService())->evaluate('jeepney');

        $this->assertNull($result);
    }
}
