<?php

namespace Tests\Feature;

use App\Enums\AppointmentStage;
use App\Enums\FollowUpStatus;
use App\Enums\LeadStage;
use App\Enums\ProposalOutcome;
use App\Enums\ProposalStage;
use App\Filament\Resources\AppointmentResource\Pages\ListAppointments;
use App\Filament\Resources\FollowUpResource\Pages\ListFollowUps;
use App\Filament\Resources\LeadResource\Pages\ListLeads;
use App\Filament\Resources\ProposalResource\Pages\ListProposals;
use App\Filament\Widgets\PipelinePulse;
use App\Models\Appointment;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression coverage for the bug class found in Pipeline Pulse's Lead,
 * Appointment, and Proposal counts: each of those models splits "is this
 * closed?" across two fields (stage/outcome plus a separate is_lost flag,
 * or Proposal's Hold outcome), and the widget originally only checked one
 * half. Follow-Up has no such split (status is the single source of
 * truth), but is covered here too for symmetry now that it's its own node
 * rather than folded into a combined Follow-Up/Appointment figure. Every
 * "Active" node's test cross-checks the widget's count against the
 * corresponding resource's own ListRecords "Pending" tab (via
 * getAllTableRecordsCount(), not a re-derived query), using fixture data
 * specifically shaped to expose the gap: a Lost record sitting in a
 * non-terminal stage for Lead/Appointment, and a Hold-outcome Proposal.
 */
class PipelinePulseWidgetTest extends TestCase
{
    use RefreshDatabase;

    private function widgetNode(string $key): array
    {
        $nodes = Livewire::test(PipelinePulse::class)->viewData('nodes');

        return collect($nodes)->firstWhere('key', $key);
    }

    /**
     * The widget shows each node's "Total"/"Active" distinction as a
     * separate small tag line above the original short label (see
     * pipeline-pulse.blade.php), rather than appended into the label text
     * itself — that combined form (e.g. "Proposal (Active)") overflowed
     * and got cut off in the actual rendered widget.
     */
    public function test_each_node_has_the_original_short_label_and_a_separate_total_or_active_tag(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $nodes = collect(Livewire::test(PipelinePulse::class)->viewData('nodes'))->keyBy('key');

        $this->assertSame('Total', $nodes['database']['tag']);
        $this->assertSame('Database', $nodes['database']['label']);

        $this->assertSame('Total', $nodes['call_record']['tag']);
        $this->assertSame('Call Record', $nodes['call_record']['label']);

        $this->assertSame('Active', $nodes['follow_up']['tag']);
        $this->assertSame('Follow-Up', $nodes['follow_up']['label']);

        $this->assertSame('Active', $nodes['appointment']['tag']);
        $this->assertSame('Appointment', $nodes['appointment']['label']);

        $this->assertSame('Active', $nodes['lead']['tag']);
        $this->assertSame('Lead', $nodes['lead']['label']);

        $this->assertSame('Active', $nodes['proposal']['tag']);
        $this->assertSame('Proposal', $nodes['proposal']['label']);

        $this->assertSame('Total', $nodes['won']['tag']);
        $this->assertSame('Won', $nodes['won']['label']);
    }

    public function test_lead_active_count_matches_the_leads_pending_tab_and_excludes_a_lost_non_terminal_lead(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        // Active: counts toward both the tab and the widget.
        Lead::create([
            'prospect_id' => Prospect::factory()->create()->id,
            'assigned_to' => $admin->id,
            'created_by' => $admin->id,
            'stage' => LeadStage::RequirementCollection,
            'temperature' => 'warm',
        ]);

        // Lost, but sitting in a non-terminal stage — must NOT count, even
        // though checking `stage` alone would say it's active.
        $lostLead = Lead::create([
            'prospect_id' => Prospect::factory()->create()->id,
            'assigned_to' => $admin->id,
            'created_by' => $admin->id,
            'stage' => LeadStage::DemoScheduledOrDone,
            'temperature' => 'warm',
        ]);
        $lostLead->markLost('Went with a competitor.');

        // Terminal stage — must NOT count.
        Lead::create([
            'prospect_id' => Prospect::factory()->create()->id,
            'assigned_to' => $admin->id,
            'created_by' => $admin->id,
            'stage' => LeadStage::Validated,
            'temperature' => 'warm',
            'notes' => 'Validated in test fixture.',
        ]);

        $pendingTabCount = Livewire::test(ListLeads::class)
            ->set('activeTab', 'pending')
            ->instance()
            ->getAllTableRecordsCount();

        $this->assertSame(1, $pendingTabCount);
        $this->assertSame($pendingTabCount, $this->widgetNode('lead')['count']);
    }

    public function test_follow_up_active_count_matches_the_follow_ups_pending_tab(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        FollowUp::create([
            'prospect_id' => Prospect::factory()->create()->id,
            'user_id' => $admin->id,
            'follow_up_at' => now()->addDay(),
            'reason' => 'Callback later',
            'status' => FollowUpStatus::Pending,
        ]);
        FollowUp::create([
            'prospect_id' => Prospect::factory()->create()->id,
            'user_id' => $admin->id,
            'follow_up_at' => now()->addDay(),
            'reason' => 'Callback later',
            'status' => FollowUpStatus::Completed,
        ]);
        FollowUp::create([
            'prospect_id' => Prospect::factory()->create()->id,
            'user_id' => $admin->id,
            'follow_up_at' => now()->addDay(),
            'reason' => 'Callback later',
            'status' => FollowUpStatus::Cancelled,
        ]);

        $pendingTabCount = Livewire::test(ListFollowUps::class)
            ->set('activeTab', 'pending')
            ->instance()
            ->getAllTableRecordsCount();

        $this->assertSame(1, $pendingTabCount);
        $this->assertSame($pendingTabCount, $this->widgetNode('follow_up')['count']);
    }

    public function test_appointment_active_count_matches_the_appointments_pending_tab_and_excludes_a_lost_non_terminal_appointment(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Appointment::create([
            'prospect_id' => Prospect::factory()->create()->id,
            'assigned_to' => $admin->id,
            'created_by' => $admin->id,
            'appointment_at' => now(),
            'stage' => AppointmentStage::AppointmentMade,
        ]);

        $lostAppointment = Appointment::create([
            'prospect_id' => Prospect::factory()->create()->id,
            'assigned_to' => $admin->id,
            'created_by' => $admin->id,
            'appointment_at' => now(),
            'stage' => AppointmentStage::VisitConducted,
        ]);
        $lostAppointment->markLost('Prospect went quiet.');

        Appointment::create([
            'prospect_id' => Prospect::factory()->create()->id,
            'assigned_to' => $admin->id,
            'created_by' => $admin->id,
            'appointment_at' => now(),
            'stage' => AppointmentStage::Succeeded,
        ]);

        $pendingTabCount = Livewire::test(ListAppointments::class)
            ->set('activeTab', 'pending')
            ->instance()
            ->getAllTableRecordsCount();

        $this->assertSame(1, $pendingTabCount);
        $this->assertSame($pendingTabCount, $this->widgetNode('appointment')['count']);
    }

    public function test_proposal_active_count_matches_the_proposals_pending_tab_and_includes_hold(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $this->makeProposal($admin, null); // no outcome yet — active
        $this->makeProposal($admin, ProposalOutcome::Hold); // paused, still active
        $this->makeProposal($admin, ProposalOutcome::Won); // decided — not active
        $this->makeProposal($admin, ProposalOutcome::Lost); // decided — not active

        $pendingTabCount = Livewire::test(ListProposals::class)
            ->set('activeTab', 'pending')
            ->instance()
            ->getAllTableRecordsCount();

        $this->assertSame(2, $pendingTabCount);
        $this->assertSame($pendingTabCount, $this->widgetNode('proposal')['count']);
    }

    private function makeProposal(User $owner, ?ProposalOutcome $outcome): Proposal
    {
        $prospect = Prospect::factory()->create();
        $lead = Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $owner->id,
            'created_by' => $owner->id,
            'stage' => LeadStage::Validated,
            'temperature' => 'hot',
            'notes' => 'Validated in test fixture.',
        ]);

        return Proposal::create([
            'lead_id' => $lead->id,
            'prospect_id' => $prospect->id,
            'assigned_to' => $owner->id,
            'created_by' => $owner->id,
            'stage' => ProposalStage::BeingPrepared,
            'outcome' => $outcome,
        ]);
    }
}
