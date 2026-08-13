<?php

namespace App\Support\Exports;

use App\Enums\AppointmentStage;
use App\Models\Appointment;
use App\Models\User;
use Filament\Forms;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AppointmentExporter extends ResourceExporter
{
    public function scopedQuery(User $user): Builder
    {
        return Appointment::query()
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
        return ['Company', 'Contact Person', 'Stage', 'Appointment At', 'Lost', 'Lost At Stage', 'Lost Reason', 'Assigned Employee', 'Created By', 'Stage Since', 'Created At'];
    }

    public function mapRow(Model $record): array
    {
        /** @var Appointment $record */
        return [
            $record->prospect->company_name,
            $record->prospect->contact_person,
            $record->stage->getLabel(),
            $record->appointment_at?->format('Y-m-d H:i'),
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
        $suffix = filled($criteria['stage'] ?? null) ? AppointmentStage::from($criteria['stage'])->getLabel() : 'All Stages';

        return "appointments-{$suffix}-".now()->format('Y-m-d').'.csv';
    }

    public function summarizeCriteria(array $criteria): string
    {
        return filled($criteria['stage'] ?? null)
            ? 'Stage: '.AppointmentStage::from($criteria['stage'])->getLabel()
            : 'All stages';
    }

    public function criteriaFormSchema(): array
    {
        return [
            Forms\Components\Select::make('stage')
                ->label('Appointment Stage')
                ->options(AppointmentStage::class)
                ->placeholder('All stages'),
        ];
    }

    public function normalizeCriteria(array $data): array
    {
        return ['stage' => filled($data['stage'] ?? null) ? $data['stage'] : null];
    }
}
