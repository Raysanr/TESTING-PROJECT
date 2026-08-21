<?php

namespace App\Services;

use App\Models\Fare;

class FareEstimator
{
    /**
     * Map an OTP leg `mode` to a `fares` table `mode` row.
     *
     * OTP's GTFS-derived `mode` only distinguishes broad categories (WALK, BUS,
     * RAIL, SUBWAY, TRAM, ...), not jeepney-vs-bus or LRT-vs-MRT — that distinction
     * isn't in the feed. Fares are flat per category, so this is a best-effort
     * mapping, not a precise one.
     */
    private const MODE_MAP = [
        'BUS' => 'bus',
        'RAIL' => 'lrt',
        'SUBWAY' => 'lrt',
        'TRAM' => 'lrt',
    ];

    /**
     * @param  array<int, array<string, mixed>>  $legs
     * @return array{legs: array<int, array<string, mixed>>, totalFare: float}
     */
    public function estimate(array $legs): array
    {
        $priced = [];
        $total = 0.0;

        foreach ($legs as $leg) {
            if (($leg['mode'] ?? null) === 'WALK') {
                $priced[] = [...$leg, 'fare' => 0.0, 'fareMode' => null];

                continue;
            }

            $fareKey = self::MODE_MAP[$leg['mode'] ?? ''] ?? 'default';
            $fare = (float) (Fare::where('mode', $fareKey)->value('base_fare')
                ?? Fare::where('mode', 'default')->value('base_fare'));

            $priced[] = [...$leg, 'fare' => $fare, 'fareMode' => $fareKey];
            $total += $fare;
        }

        return [
            'legs' => $priced,
            'totalFare' => $total,
        ];
    }
}
