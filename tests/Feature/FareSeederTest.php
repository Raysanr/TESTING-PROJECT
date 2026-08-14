<?php

namespace Tests\Feature;

use App\Models\Fare;
use Database\Seeders\FareSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FareSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_expected_fare_rows(): void
    {
        $this->seed(FareSeeder::class);

        $this->assertSame(13.0, (float) Fare::where('mode', 'jeepney')->value('base_fare'));
        $this->assertSame(13.0, (float) Fare::where('mode', 'bus')->value('base_fare'));
        $this->assertSame(20.0, (float) Fare::where('mode', 'lrt')->value('base_fare'));
        $this->assertSame(20.0, (float) Fare::where('mode', 'mrt')->value('base_fare'));
        $this->assertSame(15.0, (float) Fare::where('mode', 'default')->value('base_fare'));
    }
}
