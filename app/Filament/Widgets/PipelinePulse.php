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
use Illuminate\Database\Eloquent\Builder;

/**
 * "Pipeline Pulse" — the Main Dashboard's signature opening element
 * (admin-only, see App\Filament\Pages\MainDashboard). A live, seven-node
 * flow visualization of the real sales process:
 *
 *   Database -> Call Record -> Follow-Up -> Appointment -> Lead -> Proposal -> Won
 *
 * Every count below is a real query against current data — nothing here is
 * placeholder or hardcoded. Database/Call Record/Won are cumulative totals
 * (each node's `tag` reads "Total" in the widget, above the node's normal
 * name) — Database/Call Record are the source of everything downstream and
 * never "complete"; Won is a running tally of closed-won Proposals.
 * Follow-Up/Appointment/Lead/Proposal are "currently active" counts
 * (`tag` reads "Active"), each matching — deliberately, not coincidentally
 * — exactly what that resource's own ListRecords "Pending" tab shows: see
 * ListFollowUps/ListAppointments/ListLeads/ListProposals'
 * getTabs(). Follow-Up and Appointment are two separate nodes with their
 * own counts (not a single combined figure — that read as confusing, one
 * number couldn't be attributed to either resource). Lead and Appointment
 * in particular split "is this closed?" across two fields (stage
 * progression, plus a separate is_lost flag set by markLost() without
 * touching stage), and Proposal folds its Hold outcome into "active" too
 * (Hold is a pause, not a final decision) — this widget must replicate
 * both halves of each of those checks, not just the stage/outcome half, or
 * a closed-but-not-terminal-stage record silently over-counts as active
 * (this happened for real — Hold proposals were excluded by an earlier
 * whereNull('outcome')-only check).
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

        $activeFollowUpsCount = FollowUp::query()->where('status', FollowUpStatus::Pending)->count();

        // Lead/Appointment split "is this closed?" across two fields: the
        // normal stage progression, plus a separate is_lost flag that
        // markLost() sets *without* touching stage (see Lead::markLost()/
        // Appointment::markLost()) — so a Lost record sitting in a
        // non-terminal stage must be excluded here too, matching exactly
        // what ListLeads/ListAppointments' own "Pending" tab checks
        // (App\Filament\Resources\LeadResource\Pages\ListLeads::getTabs(),
        // AppointmentResource\Pages\ListAppointments::getTabs()).
        $activeAppointmentsCount = Appointment::query()->where('is_lost', false)->whereIn('stage', $activeAppointmentStages)->count();

        $activeLeadsQuery = Lead::query()->where('is_lost', false)->whereIn('stage', $activeLeadStages);

        // Proposal doesn't have a separate is_lost flag — Hold is one of
        // its three `outcome` values (Won/Hold/Lost) — but Hold is
        // explicitly not a final decision (ListProposals::getTabs()'s own
        // "Pending" tab treats whereNull('outcome') OR outcome=Hold as the
        // active queue), so it must count here the same way.
        $openProposalsCount = Proposal::query()
            ->where(fn (Builder $query) => $query->whereNull('outcome')->orWhere('outcome', ProposalOutcome::Hold))
            ->count();

        $nodes = [
            [
                'key' => 'database',
                'tag' => 'Total',
                'label' => 'Database',
                'count' => Prospect::query()->count(),
                'icon' => 'heroicon-o-building-office-2',
                'alert' => false,
            ],
            [
                'key' => 'call_record',
                'tag' => 'Total',
                'label' => 'Call Record',
                'count' => CallRecord::query()->count(),
                'icon' => 'heroicon-o-phone',
                'alert' => false,
            ],
            [
                'key' => 'follow_up',
                'tag' => 'Active',
                'label' => 'Follow-Up',
                'count' => $activeFollowUpsCount,
                'icon' => 'heroicon-o-arrow-path',
                'alert' => false,
            ],
            [
                'key' => 'appointment',
                'tag' => 'Active',
                'label' => 'Appointment',
                'count' => $activeAppointmentsCount,
                'icon' => 'heroicon-o-calendar-days',
                'alert' => false,
            ],
            [
                'key' => 'lead',
                'tag' => 'Active',
                'label' => 'Lead',
                'count' => (clone $activeLeadsQuery)->count(),
                'icon' => 'heroicon-o-fire',
                'alert' => (clone $activeLeadsQuery)->where('temperature', LeadTemperature::Hot)->exists()
                    || Lead::query()->stale()->exists(),
            ],
            [
                'key' => 'proposal',
                'tag' => 'Active',
                'label' => 'Proposal',
                'count' => $openProposalsCount,
                'icon' => 'heroicon-o-document-text',
                'alert' => Proposal::query()->stale()->exists(),
            ],
            [
                'key' => 'won',
                'tag' => 'Total',
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
