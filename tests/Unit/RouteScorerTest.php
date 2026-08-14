<?php

namespace Tests\Unit;

use App\Services\RouteScorer;
use PHPUnit\Framework\TestCase;

class RouteScorerTest extends TestCase
{
    public function test_ranks_fewest_transfers_first(): void
    {
        $scorer = new RouteScorer();

        $itineraries = [
            $this->itinerary(walkDistance: 200.0, legs: [
                ['mode' => 'WALK', 'to' => ['name' => 'Stop A']],
                ['mode' => 'BUS', 'route' => ['shortName' => 'Bus 1'], 'to' => ['name' => 'Stop B']],
                ['mode' => 'RAIL', 'route' => ['shortName' => 'LRT-1'], 'to' => ['name' => 'Stop C']],
                ['mode' => 'WALK', 'to' => ['name' => 'Destination']],
            ]),
            $this->itinerary(walkDistance: 500.0, legs: [
                ['mode' => 'WALK', 'to' => ['name' => 'Stop A']],
                ['mode' => 'BUS', 'route' => ['shortName' => 'Bus 2'], 'to' => ['name' => 'Destination']],
            ]),
        ];

        $ranked = $scorer->rank($itineraries);

        $this->assertSame(0, $ranked[0]['transferCount']);
        $this->assertSame(1, $ranked[1]['transferCount']);
    }

    public function test_uses_walk_distance_as_tiebreaker(): void
    {
        $scorer = new RouteScorer();

        $itineraries = [
            $this->itinerary(walkDistance: 800.0, legs: [
                ['mode' => 'WALK', 'to' => ['name' => 'Stop A']],
                ['mode' => 'BUS', 'route' => ['shortName' => 'Bus 1'], 'to' => ['name' => 'Destination']],
            ]),
            $this->itinerary(walkDistance: 200.0, legs: [
                ['mode' => 'WALK', 'to' => ['name' => 'Stop A']],
                ['mode' => 'BUS', 'route' => ['shortName' => 'Bus 2'], 'to' => ['name' => 'Destination']],
            ]),
        ];

        $ranked = $scorer->rank($itineraries);

        $this->assertSame(200.0, $ranked[0]['walkDistance']);
        $this->assertSame(800.0, $ranked[1]['walkDistance']);
    }

    public function test_assigns_difficulty_labels(): void
    {
        $scorer = new RouteScorer();

        $noTransfer = $this->itinerary(walkDistance: 100.0, legs: [
            ['mode' => 'WALK', 'to' => ['name' => 'Destination']],
            ['mode' => 'BUS', 'route' => ['shortName' => 'Bus 1'], 'to' => ['name' => 'Destination']],
        ]);
        $twoTransfers = $this->itinerary(walkDistance: 100.0, legs: [
            ['mode' => 'BUS', 'route' => ['shortName' => 'Bus 1'], 'to' => ['name' => 'Stop B']],
            ['mode' => 'RAIL', 'route' => ['shortName' => 'LRT-1'], 'to' => ['name' => 'Stop C']],
            ['mode' => 'BUS', 'route' => ['shortName' => 'Bus 2'], 'to' => ['name' => 'Destination']],
        ]);
        $threeTransfers = $this->itinerary(walkDistance: 100.0, legs: [
            ['mode' => 'BUS', 'route' => ['shortName' => 'Bus 1'], 'to' => ['name' => 'Stop B']],
            ['mode' => 'RAIL', 'route' => ['shortName' => 'LRT-1'], 'to' => ['name' => 'Stop C']],
            ['mode' => 'BUS', 'route' => ['shortName' => 'Bus 2'], 'to' => ['name' => 'Stop D']],
            ['mode' => 'RAIL', 'route' => ['shortName' => 'LRT-2'], 'to' => ['name' => 'Destination']],
        ]);

        $ranked = $scorer->rank([$noTransfer, $twoTransfers, $threeTransfers]);

        $this->assertSame('Easy', $ranked[0]['difficulty']);
        $this->assertSame('Moderate', $ranked[1]['difficulty']);
        $this->assertSame('Hard', $ranked[2]['difficulty']);
    }

    public function test_generates_leg_by_leg_instructions(): void
    {
        $scorer = new RouteScorer();

        $itinerary = $this->itinerary(walkDistance: 300.0, legs: [
            ['mode' => 'WALK', 'to' => ['name' => 'SM North EDSA Terminal']],
            ['mode' => 'BUS', 'route' => ['shortName' => 'Jeepney 32'], 'to' => ['name' => 'Quezon Ave']],
            ['mode' => 'RAIL', 'route' => ['shortName' => 'LRT-1'], 'to' => ['name' => 'Roosevelt']],
        ]);

        $ranked = $scorer->rank([$itinerary]);

        $this->assertSame([
            'Walk to SM North EDSA Terminal',
            'Ride Jeepney 32 to Quezon Ave',
            'Transfer to LRT-1 at Roosevelt',
        ], $ranked[0]['instructions']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $legs
     * @return array<string, mixed>
     */
    private function itinerary(float $walkDistance, array $legs): array
    {
        return [
            'duration' => 1200,
            'walkDistance' => $walkDistance,
            'legs' => $legs,
        ];
    }
}
