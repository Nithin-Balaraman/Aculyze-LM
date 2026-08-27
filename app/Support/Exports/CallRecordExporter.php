<?php

namespace App\Support\Exports;

use App\Enums\CallOutcome;
use App\Models\CallRecord;
use App\Models\User;
use Filament\Forms;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CallRecordExporter extends ResourceExporter
{
    private const SCOPE_OPTIONS = [
        'all' => 'All',
        'history' => 'History (outcomes with no further routing)',
    ];

    /**
     * Mirrors CallRecordResource::getEloquentQuery() exactly: visibleTo($user)
     * — admin sees every call, an employee only their own (see
     * CallRecord::scopeVisibleTo()).
     */
    public function scopedQuery(User $user): Builder
    {
        return CallRecord::query()
            ->visibleTo($user)
            ->with(['prospect', 'caller'])
            ->orderBy('called_at');
    }

    /**
     * Matches ListCallRecords' own "All"/"History" tabs: History is every
     * outcome that CallOutcome::routesNowhere() — the same set that tab's
     * modifyQueryUsing() already filters to.
     */
    public function applyCriteria(Builder $query, array $criteria): Builder
    {
        return match ($criteria['scope'] ?? 'all') {
            'history' => $query->whereIn('outcome', self::noRouteOutcomes()),
            default => $query,
        };
    }

    public function headers(): array
    {
        return ['Called At', 'Company', 'Outcome', 'Called By', 'Contact Person Spoken To', 'Follow-up At', 'Appointment At', 'Notes'];
    }

    public function mapRow(Model $record): array
    {
        /** @var CallRecord $record */
        return [
            $record->called_at->format('Y-m-d H:i'),
            $record->prospect->company_name,
            $record->outcome->getLabel(),
            $record->caller->name,
            $record->contact_person_spoken_to,
            $record->follow_up_at?->format('Y-m-d H:i'),
            $record->appointment_at?->format('Y-m-d H:i'),
            $record->notes,
        ];
    }

    public function filename(array $criteria): string
    {
        $scope = $criteria['scope'] ?? 'all';

        return "call-records-{$scope}-".now()->format('Y-m-d').'.csv';
    }

    public function summarizeCriteria(array $criteria): string
    {
        return self::SCOPE_OPTIONS[$criteria['scope'] ?? 'all'] ?? 'All';
    }

    public function criteriaFormSchema(): array
    {
        return [
            Forms\Components\Select::make('scope')
                ->label('Which call records?')
                ->options(self::SCOPE_OPTIONS)
                ->default('all')
                ->required(),
        ];
    }

    public function normalizeCriteria(array $data): array
    {
        $scope = $data['scope'] ?? 'all';

        return ['scope' => array_key_exists($scope, self::SCOPE_OPTIONS) ? $scope : 'all'];
    }

    /**
     * @return array<string>
     */
    private static function noRouteOutcomes(): array
    {
        return array_map(
            fn (CallOutcome $outcome) => $outcome->value,
            array_filter(CallOutcome::cases(), fn (CallOutcome $outcome) => $outcome->routesNowhere())
        );
    }
}
