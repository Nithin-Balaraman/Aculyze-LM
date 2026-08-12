<?php

namespace App\Support\Exports;

use App\Enums\LeadStage;
use App\Models\Lead;
use App\Models\User;
use Filament\Forms;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LeadExporter extends ResourceExporter
{
    public function scopedQuery(User $user): Builder
    {
        return Lead::query()
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
        return ['Company', 'Contact Person', 'Stage', 'Temperature', 'Lost', 'Lost At Stage', 'Lost Reason', 'Assigned Employee', 'Created By', 'Stage Since', 'Created At'];
    }

    public function mapRow(Model $record): array
    {
        /** @var Lead $record */
        return [
            $record->prospect->company_name,
            $record->prospect->contact_person,
            $record->stage->getLabel(),
            $record->temperature->getLabel(),
            $record->is_lost ? 'Yes' : 'No',
            $record->lost_at_stage?->getLabel(),
            $record->lost_reason,
            $record->assignedEmployee->name,
            $record->creator->name,
            $record->stage_changed_at?->format('Y-m-d H:i'),
            $record->created_at->format('Y-m-d H:i'),
        ];
    }

    public function filename(array $criteria): string
    {
        $suffix = filled($criteria['stage'] ?? null) ? LeadStage::from($criteria['stage'])->getLabel() : 'All Stages';

        return "leads-{$suffix}-".now()->format('Y-m-d').'.csv';
    }

    public function summarizeCriteria(array $criteria): string
    {
        return filled($criteria['stage'] ?? null)
            ? 'Stage: '.LeadStage::from($criteria['stage'])->getLabel()
            : 'All stages';
    }

    public function criteriaFormSchema(): array
    {
        return [
            Forms\Components\Select::make('stage')
                ->label('Lead Stage')
                ->options(LeadStage::class)
                ->placeholder('All stages'),
        ];
    }

    public function normalizeCriteria(array $data): array
    {
        return ['stage' => filled($data['stage'] ?? null) ? $data['stage'] : null];
    }
}
