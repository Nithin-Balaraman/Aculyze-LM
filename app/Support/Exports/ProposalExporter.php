<?php

namespace App\Support\Exports;

use App\Enums\ProposalStage;
use App\Models\Proposal;
use App\Models\User;
use Filament\Forms;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ProposalExporter extends ResourceExporter
{
    public function scopedQuery(User $user): Builder
    {
        return Proposal::query()
            ->visibleTo($user)
            ->with(['prospect', 'assignedEmployee', 'creator'])
            ->orderBy('created_at');
    }

    public function applyCriteria(Builder $query, array $criteria): Builder
    {
        if (filled($criteria['stage'] ?? null)) {
            $query->where('stage', $criteria['stage']);
        }

        return $query;
    }

    public function headers(): array
    {
        return ['Company', 'Stage', 'Outcome', 'Value', 'Sent At', 'Assigned Employee', 'Created By', 'Stage Since', 'Created At'];
    }

    public function mapRow(Model $record): array
    {
        /** @var Proposal $record */
        return [
            $record->prospect->company_name,
            $record->stage->getLabel(),
            $record->outcome?->getLabel() ?? 'In Progress',
            $record->value,
            $record->sent_at?->format('Y-m-d'),
            $record->assignedEmployee->name,
            $record->creator->name,
            $record->stage_changed_at?->format('Y-m-d H:i'),
            $record->created_at->format('Y-m-d H:i'),
        ];
    }

    public function filename(array $criteria): string
    {
        $suffix = filled($criteria['stage'] ?? null) ? ProposalStage::from($criteria['stage'])->getLabel() : 'All Stages';

        return "proposals-{$suffix}-".now()->format('Y-m-d').'.csv';
    }

    public function summarizeCriteria(array $criteria): string
    {
        return filled($criteria['stage'] ?? null)
            ? 'Stage: '.ProposalStage::from($criteria['stage'])->getLabel()
            : 'All stages';
    }

    public function criteriaFormSchema(): array
    {
        return [
            Forms\Components\Select::make('stage')
                ->label('Proposal Stage')
                ->options(ProposalStage::class)
                ->placeholder('All stages'),
        ];
    }

    public function normalizeCriteria(array $data): array
    {
        return ['stage' => filled($data['stage'] ?? null) ? $data['stage'] : null];
    }
}
