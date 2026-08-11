<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\Date;

/**
 * Turns the shared dashboard filters form data (see AGENTS.md section 34)
 * into a concrete [from, until] date range, or [null, null] for "All Time".
 * Centralized so every widget applies date filtering identically.
 */
class DashboardPeriod
{
    /**
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    public static function resolve(?array $filters): array
    {
        $period = $filters['period'] ?? 'all_time';

        return match ($period) {
            'today' => [Date::now()->startOfDay(), Date::now()->endOfDay()],
            'this_week' => [Date::now()->startOfWeek(), Date::now()->endOfWeek()],
            'this_month' => [Date::now()->startOfMonth(), Date::now()->endOfMonth()],
            'custom' => [
                filled($filters['from'] ?? null) ? Carbon::parse($filters['from'])->startOfDay() : null,
                filled($filters['until'] ?? null) ? Carbon::parse($filters['until'])->endOfDay() : null,
            ],
            default => [null, null],
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            'all_time' => 'All Time',
            'today' => 'Today',
            'this_week' => 'This Week',
            'this_month' => 'This Month',
            'custom' => 'Custom Date Range',
        ];
    }
}
