<?php

namespace App\Filament\Resources\LeadResource\Pages;

use App\Enums\LeadStage;
use App\Filament\Resources\LeadResource;
use App\Models\Lead;
use App\Support\CsvExporter;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\ListRecords;

class ListLeads extends ListRecords
{
    protected static string $resource = LeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('exportCsv')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn () => auth()->user()->isAdmin())
                ->form([
                    Forms\Components\Select::make('stage')
                        ->label('Lead Stage')
                        ->options(LeadStage::class)
                        ->placeholder('All stages'),
                ])
                ->action(function (array $data) {
                    $query = Lead::query()->with(['prospect', 'assignedEmployee', 'creator']);

                    if (filled($data['stage'] ?? null)) {
                        $query->where('stage', $data['stage']);
                    }

                    $rows = $query->orderBy('created_at')->get()->map(fn (Lead $lead) => [
                        $lead->prospect->company_name,
                        $lead->prospect->contact_person,
                        $lead->stage->getLabel(),
                        $lead->temperature->getLabel(),
                        $lead->is_lost ? 'Yes' : 'No',
                        $lead->lost_at_stage?->getLabel(),
                        $lead->lost_reason,
                        $lead->assignedEmployee->name,
                        $lead->creator->name,
                        $lead->stage_changed_at?->format('Y-m-d H:i'),
                        $lead->created_at->format('Y-m-d H:i'),
                    ]);

                    $suffix = filled($data['stage'] ?? null) ? LeadStage::from($data['stage'])->getLabel() : 'All Stages';

                    return CsvExporter::stream(
                        "leads-{$suffix}-".now()->format('Y-m-d').'.csv',
                        ['Company', 'Contact Person', 'Stage', 'Temperature', 'Lost', 'Lost At Stage', 'Lost Reason', 'Assigned Employee', 'Created By', 'Stage Since', 'Created At'],
                        $rows,
                    );
                }),
            Actions\CreateAction::make(),
        ];
    }
}
