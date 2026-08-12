<?php

namespace App\Filament\Resources\ProposalResource\Pages;

use App\Enums\ProposalStage;
use App\Filament\Resources\ProposalResource;
use App\Models\Proposal;
use App\Support\CsvExporter;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\ListRecords;

class ListProposals extends ListRecords
{
    protected static string $resource = ProposalResource::class;

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
                        ->label('Proposal Stage')
                        ->options(ProposalStage::class)
                        ->placeholder('All stages'),
                ])
                ->action(function (array $data) {
                    $query = Proposal::query()->with(['prospect', 'assignedEmployee', 'creator']);

                    if (filled($data['stage'] ?? null)) {
                        $query->where('stage', $data['stage']);
                    }

                    $rows = $query->orderBy('created_at')->get()->map(fn (Proposal $proposal) => [
                        $proposal->prospect->company_name,
                        $proposal->stage->getLabel(),
                        $proposal->outcome?->getLabel() ?? 'In Progress',
                        $proposal->value,
                        $proposal->sent_at?->format('Y-m-d'),
                        $proposal->assignedEmployee->name,
                        $proposal->creator->name,
                        $proposal->stage_changed_at?->format('Y-m-d H:i'),
                        $proposal->created_at->format('Y-m-d H:i'),
                    ]);

                    $suffix = filled($data['stage'] ?? null) ? ProposalStage::from($data['stage'])->getLabel() : 'All Stages';

                    return CsvExporter::stream(
                        "proposals-{$suffix}-".now()->format('Y-m-d').'.csv',
                        ['Company', 'Stage', 'Outcome', 'Value', 'Sent At', 'Assigned Employee', 'Created By', 'Stage Since', 'Created At'],
                        $rows,
                    );
                }),
            Actions\CreateAction::make(),
        ];
    }
}
