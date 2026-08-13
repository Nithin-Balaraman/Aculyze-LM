<?php

namespace App\Filament\Resources\LeadResource\Pages;

use App\Enums\ExportableResource;
use App\Enums\LeadStage;
use App\Filament\Resources\LeadResource;
use App\Models\Lead;
use App\Support\Exports\ExportActions;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

/**
 * Same Pending / History / Lost tab layout as Follow-Ups
 * (FollowUpResource/Pages/ListFollowUps.php): "Pending" is the active queue
 * (not lost, not yet in a terminal stage), "History" is a UI-only filter —
 * terminal-stage and Lost Leads together, not a new status — and "Lost" is
 * the existing `is_lost` flag (see Lead::markLost()). Lost intentionally
 * overlaps with History for the same reason it does on Follow-Ups: it's a
 * narrower view of the same underlying records, not a mutually-exclusive
 * bucket.
 */
class ListLeads extends ListRecords
{
    protected static string $resource = LeadResource::class;

    public function getTabs(): array
    {
        return [
            'pending' => Tab::make('Pending')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_lost', false)->whereNotIn('stage', self::terminalStages()))
                ->badge(fn () => Lead::query()->visibleTo(auth()->user())->where('is_lost', false)->whereNotIn('stage', self::terminalStages())->count()),
            'history' => Tab::make('History')
                ->modifyQueryUsing(fn (Builder $query) => $query->where(
                    fn (Builder $query) => $query->where('is_lost', true)->orWhereIn('stage', self::terminalStages())
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
            fn (LeadStage $stage) => $stage->value,
            array_filter(LeadStage::cases(), fn (LeadStage $stage) => $stage->isTerminal())
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            ExportActions::immediate(ExportableResource::Lead),
            ExportActions::request(ExportableResource::Lead),
            Actions\CreateAction::make(),
        ];
    }
}
