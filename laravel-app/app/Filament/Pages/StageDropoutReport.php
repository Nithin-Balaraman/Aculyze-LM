<?php

namespace App\Filament\Pages;

use App\Enums\AppointmentStage;
use App\Enums\LeadStage;
use App\Enums\ProposalStage;
use App\Models\Appointment;
use App\Models\Lead;
use App\Models\Proposal;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;

/**
 * Admin-only funnel-leakage visibility (Change Request "Decision 1"): for
 * each stage of each of the three independent stage pipelines (Lead,
 * Appointment, Proposal — this app has no single unified "stage"), shows
 * how many records currently sit at that stage vs. how many have moved
 * past it. A simple aggregate, not a new data model — "moved past" is
 * computed purely from each enum's existing declaration order, which is
 * also each stage's provisional progression order.
 */
class StageDropoutReport extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-funnel';

    protected static ?string $navigationLabel = 'Stage Dropout Report';

    protected static ?string $navigationGroup = 'Reports';

    protected static string $view = 'filament.pages.stage-dropout-report';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    /**
     * @param  class-string  $enumClass
     * @param  Builder<*>  $query
     * @return array<int, array{label: string, current: int, movedPast: int}>
     */
    private function buildFunnel(string $enumClass, Builder $query): array
    {
        $cases = $enumClass::cases();
        $counts = (clone $query)->selectRaw('stage, count(*) as aggregate')->groupBy('stage')->pluck('aggregate', 'stage');

        return collect($cases)->map(function ($stage, int $index) use ($cases, $counts) {
            $laterStages = collect($cases)->slice($index + 1)->map(fn ($s) => $s->value);

            return [
                'label' => $stage->getLabel(),
                'current' => (int) ($counts[$stage->value] ?? 0),
                'movedPast' => (int) $counts->only($laterStages->all())->sum(),
            ];
        })->all();
    }

    /**
     * @return array<string, array<int, array{label: string, current: int, movedPast: int}>>
     */
    public function getFunnels(): array
    {
        return [
            'Lead' => $this->buildFunnel(LeadStage::class, Lead::query()),
            'Appointment' => $this->buildFunnel(AppointmentStage::class, Appointment::query()),
            'Proposal' => $this->buildFunnel(ProposalStage::class, Proposal::query()),
        ];
    }
}
