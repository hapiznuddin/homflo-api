<?php

namespace App\Services\Finance;

use Carbon\Carbon;

/**
 * Single definition of calendar-period bounds for every financial
 * aggregate (Actual Spending, Income, Net Cash Flow). Sharing this
 * helper keeps Dashboard and Budget totals consistent by construction:
 * every aggregate sees the same inclusive start, the same business-day
 * cap, and the same empty bounds for not-yet-started periods.
 */
final class PeriodBounds
{
    /**
     * Inclusive calendar bounds capped at the business reference date.
     * Returns [null, null] when the period has not started yet.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function effectiveBounds(int $year, int $month, string $today): array
    {
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = Carbon::createFromDate($year, $month, 1)->endOfMonth()->toDateString();
        $effectiveEnd = min($end, $today);

        if ($effectiveEnd < $start) {
            return [null, null];
        }

        return [$start, $effectiveEnd];
    }

    /**
     * Single server-side business reference date (Asia/Jakarta) for the
     * whole operation. Never the browser clock. The global app timezone
     * and timestamp storage are untouched.
     */
    public static function businessToday(): string
    {
        return Carbon::now('Asia/Jakarta')->toDateString();
    }
}
