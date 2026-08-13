<?php

namespace App\Support\Exports;

use App\Enums\FollowUpStatus;
use App\Models\FollowUp;
use App\Models\User;
use Filament\Forms;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class FollowUpExporter extends ResourceExporter
{
    private const SCOPE_OPTIONS = [
        'pending' => 'Pending',
        'history' => 'History (Completed + Cancelled)',
        'all' => 'All',
    ];

    public function scopedQuery(User $user): Builder
    {
        return FollowUp::query()
            ->visibleTo($user)
            ->with(['prospect', 'responsibleEmployee'])
            ->orderBy('follow_up_at');
    }

    public function applyCriteria(Builder $query, array $criteria): Builder
    {
        return match ($criteria['scope'] ?? 'pending') {
            'pending' => $query->where('status', FollowUpStatus::Pending),
            'history' => $query->whereIn('status', [FollowUpStatus::Completed, FollowUpStatus::Cancelled]),
            default => $query,
        };
    }

    public function headers(): array
    {
        return ['Company', 'Reason', 'Status', 'Follow-up At', 'Employee', 'Created At'];
    }

    public function mapRow(Model $record): array
    {
        /** @var FollowUp $record */
        return [
            $record->prospect->company_name,
            $record->reason,
            $record->status->getLabel(),
            $record->follow_up_at?->format('Y-m-d H:i'),
            $record->responsibleEmployee->name,
            $record->created_at->format('Y-m-d H:i'),
        ];
    }

    public function filename(array $criteria): string
    {
        $scope = $criteria['scope'] ?? 'pending';

        return "follow-ups-{$scope}-".now()->format('Y-m-d').'.csv';
    }

    public function summarizeCriteria(array $criteria): string
    {
        return self::SCOPE_OPTIONS[$criteria['scope'] ?? 'pending'] ?? 'Pending';
    }

    public function criteriaFormSchema(): array
    {
        return [
            Forms\Components\Select::make('scope')
                ->label('Which follow-ups?')
                ->options(self::SCOPE_OPTIONS)
                ->default('pending')
                ->required(),
        ];
    }

    public function normalizeCriteria(array $data): array
    {
        $scope = $data['scope'] ?? 'pending';

        return ['scope' => array_key_exists($scope, self::SCOPE_OPTIONS) ? $scope : 'pending'];
    }
}
