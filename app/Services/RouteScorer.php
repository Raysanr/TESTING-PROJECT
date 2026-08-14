<?php

namespace App\Services;

class RouteScorer
{
    /**
     * @param  array<int, array<string, mixed>>  $itineraries
     * @return array<int, array<string, mixed>> the same itineraries, sorted best-first and
     *                                            augmented with transferCount, difficulty, instructions
     */
    public function rank(array $itineraries): array
    {
        $scored = array_map(function (array $itinerary) {
            $transferCount = $this->transferCount($itinerary['legs']);

            return [
                ...$itinerary,
                'transferCount' => $transferCount,
                'difficulty' => $this->difficulty($transferCount),
                'instructions' => $this->instructions($itinerary['legs']),
            ];
        }, $itineraries);

        usort($scored, fn (array $a, array $b) => $a['transferCount'] <=> $b['transferCount']
            ?: $a['walkDistance'] <=> $b['walkDistance']);

        return $scored;
    }

    /**
     * @param  array<int, array<string, mixed>>  $legs
     */
    private function transferCount(array $legs): int
    {
        $transitLegs = array_filter($legs, fn (array $leg) => ($leg['mode'] ?? null) !== 'WALK');

        return max(0, count($transitLegs) - 1);
    }

    private function difficulty(int $transferCount): string
    {
        return match (true) {
            $transferCount <= 1 => 'Easy',
            $transferCount === 2 => 'Moderate',
            default => 'Hard',
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $legs
     * @return array<int, string>
     */
    private function instructions(array $legs): array
    {
        $lines = [];
        $boardedTransit = false;

        foreach ($legs as $leg) {
            $to = $leg['to']['name'] ?? 'destination';

            if (($leg['mode'] ?? null) === 'WALK') {
                $lines[] = "Walk to {$to}";

                continue;
            }

            $route = $leg['route']['shortName'] ?? $leg['route']['longName'] ?? ucfirst(strtolower($leg['mode']));

            $lines[] = $boardedTransit
                ? "Transfer to {$route} at {$to}"
                : "Ride {$route} to {$to}";

            $boardedTransit = true;
        }

        return $lines;
    }
}
