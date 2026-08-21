<?php

namespace App\Http\Controllers;

use App\Models\Fare;
use App\Models\FareReport;
use App\Services\FareCorroborationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FareReportController extends Controller
{
    public function __construct(
        private readonly FareCorroborationService $corroboration,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', 'string', Rule::exists('fares', 'mode')],
            'reported_fare' => ['required', 'numeric', 'between:1,200'],
        ]);

        FareReport::create([
            'mode' => $data['mode'],
            'reported_fare' => $data['reported_fare'],
            'client_hash' => hash('sha256', $request->ip().'|'.$request->userAgent()),
        ]);

        $updatedFare = $this->corroboration->evaluate($data['mode']);

        if ($updatedFare !== null) {
            return response()->json(['status' => 'updated', 'currentFare' => $updatedFare]);
        }

        $currentFare = (float) Fare::where('mode', $data['mode'])->value('base_fare');

        return response()->json(['status' => 'recorded', 'currentFare' => $currentFare]);
    }
}
