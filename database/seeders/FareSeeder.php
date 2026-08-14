<?php

namespace Database\Seeders;

use App\Models\Fare;
use Illuminate\Database\Seeder;

class FareSeeder extends Seeder
{
    public function run(): void
    {
        $fares = [
            'jeepney' => 13.00,
            'bus' => 13.00,
            'lrt' => 20.00,
            'mrt' => 20.00,
            'default' => 15.00,
        ];

        foreach ($fares as $mode => $baseFare) {
            Fare::updateOrCreate(['mode' => $mode], ['base_fare' => $baseFare]);
        }
    }
}
