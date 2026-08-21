<?php

namespace Tests\Feature;

use App\Models\Fare;
use App\Services\FareEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FareEstimatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_prices_legs_against_db_fares(): void
    {
        Fare::create(['mode' => 'jeepney', 'base_fare' => 13.00]);
        Fare::create(['mode' => 'bus', 'base_fare' => 13.00]);
        Fare::create(['mode' => 'lrt', 'base_fare' => 20.00]);
        Fare::create(['mode' => 'mrt', 'base_fare' => 20.00]);
        Fare::create(['mode' => 'default', 'base_fare' => 15.00]);

        $result = (new FareEstimator())->estimate([
            ['mode' => 'WALK', 'distance' => 100.0],
            ['mode' => 'BUS', 'distance' => 3000.0],
            ['mode' => 'SUBWAY', 'distance' => 8000.0],
        ]);

        $this->assertSame(33.0, $result['totalFare']);
        $this->assertSame(0.0, $result['legs'][0]['fare']);
        $this->assertSame(13.0, $result['legs'][1]['fare']);
        $this->assertSame(20.0, $result['legs'][2]['fare']);
    }

    public function test_falls_back_to_default_fare_for_unmapped_mode(): void
    {
        Fare::create(['mode' => 'default', 'base_fare' => 15.00]);

        $result = (new FareEstimator())->estimate([
            ['mode' => 'FERRY', 'distance' => 1000.0],
        ]);

        $this->assertSame(15.0, $result['legs'][0]['fare']);
    }

    public function test_includes_fare_mode_on_each_priced_leg(): void
    {
        Fare::create(['mode' => 'bus', 'base_fare' => 13.00]);
        Fare::create(['mode' => 'default', 'base_fare' => 15.00]);

        $result = (new FareEstimator())->estimate([
            ['mode' => 'WALK', 'distance' => 100.0],
            ['mode' => 'BUS', 'distance' => 3000.0],
        ]);

        $this->assertNull($result['legs'][0]['fareMode']);
        $this->assertSame('bus', $result['legs'][1]['fareMode']);
    }
}
