<?php

namespace App\Filament\Widgets;

use App\Enums\AppointmentStage;
use App\Enums\FollowUpStatus;
use App\Enums\LeadStage;
use App\Enums\LeadTemperature;
use App\Enums\ProposalOutcome;
use App\Models\Appointment;
use App\Models\CallRecord;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\Prospect;
use Filament\Widgets\Widget;

/**
 * "Pipeline Pulse" — the Main Dashboard's signature opening element
 * (admin-only, see App\Filament\Pages\MainDashboard). A live, six-stage
 * flow visualization of the real sales process:
 *
 *   Database -> Call Record -> Follow-Up/Appointment -> Lead -> Proposal -> Won
 *
 * Every count below is a real query against current data — nothing here is
 * placeholder or hardcoded. Database/Call Record show cumulative totals
 * (they're the source of everything downstream and never "complete");
 * Follow-Up/Appointment/Lead/Proposal show what's currently active/open at
 * that station of the pipeline; Won is a cumulative count of closed-won
 * Proposals.
 *
 * The coral highlight on a node (see resources/views/filament/widgets/
 * pipeline-pulse.blade.php and the `.pulse-node-alert` animation in
 * resources/css/filament/admin/theme.css) is reserved for the same two
 * things coral means everywhere else in this app: Hot leads and stale
 * records — see App\Enums\LeadTemperature.
 */
class PipelinePulse extends Widget
{
    protected static string $view = 'filament.widgets.pipeline-pulse';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -20;

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $activeAppointmentStages = array_map(
            fn (AppointmentStage $stage) => $stage->value,
            array_filter(AppointmentStage::cases(), fn (AppointmentStage $stage) => ! $stage->isTerminal()),
        );

        $activeLeadStages = array_map(
            fn (LeadStage $stage) => $stage->value,
            array_filter(LeadStage::cases(), fn (LeadStage $stage) => ! $stage->isTerminal()),
        );

        $followUpAppointmentCount = FollowUp::query()->where('status', FollowUpStatus::Pending)->count()
            + Appointment::query()->whereIn('stage', $activeAppointmentStages)->count();

        $activeLeadsQuery = Lead::query()->whereIn('stage', $activeLeadStages);

        $openProposalsCount = Proposal::query()->whereNull('outcome')->count();

        $nodes = [
            [
                'key' => 'database',
                'label' => 'Database',
                'count' => Prospect::query()->count(),
                'icon' => 'heroicon-o-building-office-2',
                'alert' => false,
            ],
            [
                'key' => 'call_record',
                'label' => 'Call Record',
                'count' => CallRecord::query()->count(),
                'icon' => 'heroicon-o-phone',
                'alert' => false,
            ],
            [
                'key' => 'follow_up_appointment',
                'label' => 'Follow-Up / Appointment',
                'count' => $followUpAppointmentCount,
                'icon' => 'heroicon-o-calendar-days',
                'alert' => false,
            ],
            [
                'key' => 'lead',
                'label' => 'Lead',
                'count' => (clone $activeLeadsQuery)->count(),
                'icon' => 'heroicon-o-fire',
                'alert' => (clone $activeLeadsQuery)->where('temperature', LeadTemperature::Hot)->exists()
                    || Lead::query()->stale()->exists(),
            ],
            [
                'key' => 'proposal',
                'label' => 'Proposal',
                'count' => $openProposalsCount,
                'icon' => 'heroicon-o-document-text',
                'alert' => Proposal::query()->stale()->exists(),
            ],
            [
                'key' => 'won',
                'label' => 'Won',
                'count' => Proposal::query()->where('outcome', ProposalOutcome::Won)->count(),
                'icon' => 'heroicon-o-trophy',
                'alert' => false,
            ],
        ];

        $maxCount = max(1, ...array_column($nodes, 'count'));

        // Connector[i] sits between node[i] and node[i+1]; its visual
        // weight reflects the destination node's share of the busiest
        // node's volume, floored so a zero-count stage still reads as a
        // thin (not invisible) line.
        $connectors = [];
        for ($i = 0; $i < count($nodes) - 1; $i++) {
            $share = $nodes[$i + 1]['count'] / $maxCount;
            $connectors[] = [
                'thickness' => max(2, round($share * 10)),
                'alert' => $nodes[$i + 1]['alert'],
            ];
        }

        return [
            'nodes' => $nodes,
            'connectors' => $connectors,
        ];
    }
}
