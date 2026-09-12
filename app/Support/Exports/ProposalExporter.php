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

    /**
     * "Sent At (Legacy)" — Phase 4A-3.6: exports the legacy Proposal.sent_at
     * field, which predates the commercial Version workflow and is no
     * longer written by any runtime service. It is NOT the authoritative
     * send record (that is ProposalVersion.sent_at / proposal_sends,
     * visible on the Commercial Version page) — labeled explicitly so an
     * exported spreadsheet cannot be mistaken for real send history.
     */
    public function headers(): array
    {
        return ['Company', 'Stage', 'Outcome', 'Value', 'Sent At (Legacy)', 'Assigned Employee', 'Created By', 'Stage Since', 'Created At'];
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
