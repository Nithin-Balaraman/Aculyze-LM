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
 *
 * Phase 3: deliberately kept as a LEGACY-stage-only report, not migrated to
 * normalized status/outcome — Lead and Appointment now have a normalized
 * status, but Proposal still doesn't (out of Phase 3 scope), and comparing
 * Lead/Appointment on one axis while Proposal stays on another would make
 * this one report mix unlike concepts across its three columns. A proper
 * normalized reporting redesign is deferred to a later Management/Data Ops
 * reporting phase, once Proposal's own state model is also addressed. Until
 * then this page is explicitly labeled "Legacy" so it is never mistaken for
 * the authoritative current-workflow view — see the dashboards/widgets that
 * already read normalized status (PipelinePulse, Leads by Status) for that.
 */
class StageDropoutReport extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-funnel';

    protected static ?string $navigationLabel = 'Legacy Stage Dropout';

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

    /**
     * Chart.js data shaped directly from the same $stages array the table
     * renders from (UX Fixes Batch Issue 4) — there is no second query or
     * separate calculation, so the table and chart can never disagree.
     *
     * @param  array<int, array{label: string, current: int, movedPast: int}>  $stages
     * @return array<string, mixed>
     */
    public function chartData(array $stages): array
    {
        return [
            'labels' => array_column($stages, 'label'),
            'datasets' => [
                [
                    'label' => 'Currently Here',
                    'data' => array_column($stages, 'current'),
                    'backgroundColor' => '#4174B9',
                ],
                [
                    'label' => 'Moved Past',
                    'data' => array_column($stages, 'movedPast'),
                    'backgroundColor' => '#2DC4ED',
                ],
            ],
        ];
    }

    /**
     * @param  array<int, array{label: string, current: int, movedPast: int}>  $stages
     */
    public function hasAnyData(array $stages): bool
    {
        foreach ($stages as $stage) {
            if ($stage['current'] > 0 || $stage['movedPast'] > 0) {
                return true;
            }
        }

        return false;
    }
}
