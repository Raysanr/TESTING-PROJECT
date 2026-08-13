<?php

namespace App\Services;

class FareEstimator
{
    /**
     * Map an OTP leg `mode` to a config/fares.php key.
     *
     * OTP's GTFS-derived `mode` only distinguishes broad categories (WALK, BUS,
     * RAIL, SUBWAY, TRAM, ...), not jeepney-vs-bus or LRT-vs-MRT — that distinction
     * isn't in the feed. Phase 1's stub fares are flat per category, so this is a
     * best-effort mapping, not a precise one.
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
                $priced[] = [...$leg, 'fare' => 0.0];

                continue;
            }

            $fareKey = self::MODE_MAP[$leg['mode'] ?? ''] ?? 'default';
            $fare = (float) config("fares.{$fareKey}", config('fares.default'));

            $priced[] = [...$leg, 'fare' => $fare];
            $total += $fare;
        }

        return [
            'legs' => $priced,
            'totalFare' => $total,
        ];
    }
}
