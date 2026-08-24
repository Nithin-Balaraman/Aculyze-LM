<?php

namespace App\Filament\Resources\CallRecordResource\Pages;

use App\Enums\CallOutcome;
use App\Enums\ExportableResource;
use App\Filament\Resources\CallRecordResource;
use App\Support\Exports\ExportActions;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

/**
 * "All" / "History" tabs, added alongside the Future Opportunity routing fix
 * (App\Enums\CallOutcome::routesToAppointment() no longer includes it — see
 * App\Services\CallRoutingService). A Call Record isn't a pending queue item
 * the way a Follow-Up/Lead/Appointment/Proposal is — it's already-completed
 * activity — so there's no "Pending" tab here, just the full log ("All",
 * unchanged from before tabs existed) and a narrower view ("History") of
 * calls whose outcome doesn't route anywhere else. History intentionally
 * overlaps All, same as "Lost" overlaps "History" elsewhere in this app.
 */
class ListCallRecords extends ListRecords
{
    protected static string $resource = CallRecordResource::class;

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            'history' => Tab::make('History')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('outcome', self::noRouteOutcomes())),
        ];
    }

    /**
     * @return array<string>
     */
    private static function noRouteOutcomes(): array
    {
        return array_map(
            fn (CallOutcome $outcome) => $outcome->value,
            array_filter(CallOutcome::cases(), fn (CallOutcome $outcome) => $outcome->routesNowhere())
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            ExportActions::immediate(ExportableResource::CallRecord),
            ExportActions::request(ExportableResource::CallRecord),
            Actions\CreateAction::make(),
        ];
    }
}
