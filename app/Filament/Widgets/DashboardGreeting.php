<?php

namespace App\Filament\Widgets;

use App\Models\CallRecord;
use App\Support\DashboardPeriod;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Date;

/**
 * The dashboard's opening greeting — reused on both the admin Main
 * Dashboard (employeeId left null -> company-wide "your team") and the
 * per-employee dashboards (employeeId set -> personal "you"), same
 * employeeId-optional pattern as KpiBand.
 *
 * The subtitle's call count is deliberately the exact same query KpiBand's
 * "Calls" tile already runs (App\Filament\Widgets\KpiBand::getTiles() —
 * CallRecord::query()->directlyLogged(), scoped by user_id, filtered by
 * the same selected period) rather than a new metric invented for this
 * widget — confirmed this data (already shown elsewhere on the same
 * dashboard) was the only thing reasonably available to summarize here
 * without fabricating something new.
 */
class DashboardGreeting extends Widget
{
    use InteractsWithPageFilters;

    protected static string $view = 'filament.widgets.dashboard-greeting';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -30;

    public ?int $employeeId = null;

    public function getGreeting(): string
    {
        $hour = Date::now()->hour;

        $time = match (true) {
            $hour < 12 => 'morning',
            $hour < 18 => 'afternoon',
            default => 'evening',
        };

        $firstName = explode(' ', trim(auth()->user()?->name ?? ''))[0] ?? '';

        return "Good {$time}, {$firstName}";
    }

    public function getDateLine(): string
    {
        return Date::now()->format('l \\· j F');
    }

    public function getSubtitle(): string
    {
        [$from, $until] = DashboardPeriod::resolve($this->filters);

        $query = CallRecord::query()->directlyLogged();

        if ($this->employeeId) {
            $query->where('user_id', $this->employeeId);
        }

        $count = $query
            ->when($from, fn ($q) => $q->where('called_at', '>=', $from))
            ->when($until, fn ($q) => $q->where('called_at', '<=', $until))
            ->count();

        $calls = $count === 1 ? 'call' : 'calls';
        $who = $this->employeeId ? "You've" : 'Your team has';

        return "{$who} logged {$count} {$calls} this cycle.";
    }
}
