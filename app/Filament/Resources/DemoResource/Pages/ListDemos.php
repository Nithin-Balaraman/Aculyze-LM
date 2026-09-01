<?php

namespace App\Filament\Resources\DemoResource\Pages;

use App\Enums\DemoStatus;
use App\Filament\Resources\DemoResource;
use App\Models\Demo;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

/**
 * Demo has no legacy stage and no is_lost concept — Pending/History is
 * purely normalized DemoStatus (Scheduled = active; Rescheduled/Cancelled/
 * Completed = history), mirroring Appointment/Follow-Up's own tab shape
 * without any legacy-compatibility half.
 */
class ListDemos extends ListRecords
{
    protected static string $resource = DemoResource::class;

    public function getTabs(): array
    {
        return [
            'pending' => Tab::make('Pending')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', DemoStatus::Scheduled))
                ->badge(fn () => Demo::query()->visibleTo(auth()->user())->where('status', DemoStatus::Scheduled)->count()),
            'history' => Tab::make('History')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [
                    DemoStatus::Rescheduled->value,
                    DemoStatus::Cancelled->value,
                    DemoStatus::Completed->value,
                ])),
        ];
    }
}
