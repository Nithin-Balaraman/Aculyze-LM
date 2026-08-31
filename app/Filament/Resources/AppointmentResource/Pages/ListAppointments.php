<?php

namespace App\Filament\Resources\AppointmentResource\Pages;

use App\Enums\AppointmentStage;
use App\Enums\ExportableResource;
use App\Filament\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Support\Exports\ExportActions;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

/**
 * Same Pending / History / Lost tab layout as Follow-Ups and Leads (see
 * LeadResource/Pages/ListLeads.php): "Pending" is the active queue (not
 * lost, not yet in a terminal stage), "History" is a UI-only filter —
 * terminal-stage and Lost Appointments together — and "Lost" is the
 * `is_lost` flag (see Appointment::markLost()).
 */
class ListAppointments extends ListRecords
{
    protected static string $resource = AppointmentResource::class;

    public function getTabs(): array
    {
        return [
            'pending' => Tab::make('Pending')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_lost', false)->whereNotIn('stage', self::terminalStages())->excludingHistoricalStatus())
                ->badge(fn () => Appointment::query()->visibleTo(auth()->user())->where('is_lost', false)->whereNotIn('stage', self::terminalStages())->excludingHistoricalStatus()->count()),
            'history' => Tab::make('History')
                ->modifyQueryUsing(fn (Builder $query) => $query->where(
                    fn (Builder $query) => $query->where('is_lost', true)->orWhereIn('stage', self::terminalStages())->orWhere->historicalStatus()
                )),
            'lost' => Tab::make('Lost')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_lost', true)),
        ];
    }

    /**
     * @return array<string>
     */
    private static function terminalStages(): array
    {
        return array_map(
            fn (AppointmentStage $stage) => $stage->value,
            array_filter(AppointmentStage::cases(), fn (AppointmentStage $stage) => $stage->isTerminal())
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            ExportActions::immediate(ExportableResource::Appointment),
            ExportActions::request(ExportableResource::Appointment),
            Actions\CreateAction::make(),
        ];
    }
}
