<?php

namespace App\Services;

use App\Models\Fare;
use App\Models\FareReport;
use Illuminate\Support\Facades\DB;

class FareCorroborationService
{
    private const MIN_CORROBORATING_REPORTS = 3;

    private const TOLERANCE = 1.00;

    private const WINDOW_DAYS = 14;

    public function evaluate(string $mode): ?float
    {
        $reports = FareReport::where('mode', $mode)
            ->where('applied', false)
            ->where('created_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->orderBy('created_at')
            ->get();

        if ($reports->count() < self::MIN_CORROBORATING_REPORTS) {
            return null;
        }

        $agreeing = $this->findAgreeingGroup($reports);

        if ($agreeing === null) {
            return null;
        }

        $newFare = round($agreeing->avg('reported_fare'), 2);

        DB::transaction(function () use ($mode, $newFare, $agreeing) {
            Fare::where('mode', $mode)->update(['base_fare' => $newFare]);
            FareReport::whereIn('id', $agreeing->pluck('id'))->update(['applied' => true]);
        });

        return $newFare;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, FareReport>  $reports
     * @return \Illuminate\Support\Collection<int, FareReport>|null
     */
    private function findAgreeingGroup($reports)
    {
        foreach ($reports as $anchor) {
            $group = $reports->filter(
                fn (FareReport $report) => abs($report->reported_fare - $anchor->reported_fare) <= self::TOLERANCE
            );

            if ($group->count() >= self::MIN_CORROBORATING_REPORTS) {
                return $group;
            }
        }

        return null;
    }
}
