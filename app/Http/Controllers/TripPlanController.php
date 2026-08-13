<?php

namespace App\Http\Controllers;

use App\Services\FareEstimator;
use App\Services\TripPlannerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class TripPlanController extends Controller
{
    public function __construct(
        private readonly TripPlannerService $planner,
        private readonly FareEstimator $fares,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_lat' => ['required', 'numeric'],
            'from_lon' => ['required', 'numeric'],
            'to_lat' => ['required', 'numeric'],
            'to_lon' => ['required', 'numeric'],
        ]);

        try {
            $itineraries = $this->planner->plan(
                $data['from_lat'],
                $data['from_lon'],
                $data['to_lat'],
                $data['to_lon'],
            );
        } catch (RuntimeException) {
            return response()->json([
                'error' => 'otp_unavailable',
            ], 503);
        }

        if (empty($itineraries)) {
            return response()->json([
                'legs' => [],
                'error' => 'no_route',
            ]);
        }

        $itinerary = $itineraries[0];
        $priced = $this->fares->estimate($itinerary['legs']);

        return response()->json([
            'legs' => $priced['legs'],
            'totalFare' => $priced['totalFare'],
            'totalDuration' => $itinerary['duration'],
            'walkDistance' => $itinerary['walkDistance'],
        ]);
    }
}
