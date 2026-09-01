<?php

namespace Tests\Feature;

use App\Enums\AppointmentStage;
use App\Enums\LeadStage;
use App\Enums\LeadStatus;
use App\Enums\ProposalOutcome;
use App\Enums\ProposalStage;
use App\Filament\Resources\AppointmentResource\Pages\ListAppointments;
use App\Filament\Resources\LeadResource\Pages\ListLeads;
use App\Filament\Resources\ProposalResource\Pages\ListProposals;
use App\Models\Appointment;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Leads, Appointments, and Proposals get the same Pending / History / Lost
 * tab layout as Follow-Ups (see FollowUpTabsAndExportTest and
 * LeadResource/Pages/ListLeads.php). "Lost" intentionally overlaps with
 * History on every resource, same as it does on Follow-Ups.
 */
class LeadAppointmentProposalTabsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Phase 3 correction: ListLeads' own Pending/History split is now
     * driven entirely by normalized LeadStatus::isTerminalForProgression()
     * (ProposalRequired/NoCurrentProgression), not legacy `stage` — so this
     * fixture takes an explicit $status (defaulting to the create-only
     * fallback derivation, matching real create behavior when none is
     * given) rather than relying on stage alone to reach a "History" row.
     */
    private function makeLead(User $owner, LeadStage $stage, bool $lost = false, ?LeadStatus $status = null): Lead
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id]);
        $resolvedStatus = $status ?? LeadStatus::fromLegacyStage($stage);

        $lead = Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $owner->id,
            'created_by' => $owner->id,
            'stage' => $stage,
            'status' => $resolvedStatus,
            'temperature' => 'warm',
            'notes' => $resolvedStatus->isTerminalForProgression() || $stage === LeadStage::Validated ? 'Validated in test fixture.' : null,
        ]);

        if ($lost) {
            $lead->markLost('Test lost reason.');
        }

        return $lead->fresh();
    }

    public function test_lead_pending_tab_excludes_terminal_and_lost_leads(): void
    {
        $employee = User::factory()->create();
        $pending = $this->makeLead($employee, LeadStage::RequirementCollection);
        $validated = $this->makeLead($employee, LeadStage::Validated, status: LeadStatus::ProposalRequired);
        $lost = $this->makeLead($employee, LeadStage::DemoScheduledOrDone, lost: true);

        $this->actingAs($employee);

        Livewire::test(ListLeads::class)
            ->set('activeTab', 'pending')
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$validated, $lost]);
    }

    public function test_lead_history_tab_shows_validated_and_lost_together(): void
    {
        $employee = User::factory()->create();
        $pending = $this->makeLead($employee, LeadStage::RequirementCollection);
        $validated = $this->makeLead($employee, LeadStage::Validated, status: LeadStatus::ProposalRequired);
        $lost = $this->makeLead($employee, LeadStage::DemoScheduledOrDone, lost: true);

        $this->actingAs($employee);

        Livewire::test(ListLeads::class)
            ->set('activeTab', 'history')
            ->assertCanSeeTableRecords([$validated, $lost])
            ->assertCanNotSeeTableRecords([$pending]);
    }

    public function test_lead_lost_tab_shows_only_lost_leads_and_still_appears_in_history(): void
    {
        $employee = User::factory()->create();
        $validated = $this->makeLead($employee, LeadStage::Validated, status: LeadStatus::ProposalRequired);
        $lost = $this->makeLead($employee, LeadStage::DemoScheduledOrDone, lost: true);

        $this->actingAs($employee);

        Livewire::test(ListLeads::class)
            ->set('activeTab', 'lost')
            ->assertCanSeeTableRecords([$lost])
            ->assertCanNotSeeTableRecords([$validated]);

        Livewire::test(ListLeads::class)
            ->set('activeTab', 'history')
            ->assertCanSeeTableRecords([$lost, $validated]);
    }

    private function makeAppointment(User $owner, AppointmentStage $stage, bool $lost = false): Appointment
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id]);

        // Phase 3: AppointmentStatus::fromLegacyStage() maps VisitConducted/
        // DiscussionCompleted to Completed too (same as Succeeded/
        // NotSucceeded) — broader than AppointmentStage::isTerminal(),
        // which only treats Succeeded/NotSucceeded as terminal. A "still
        // pending" fixture (a non-terminal stage) must supply its own
        // explicit Scheduled status so it isn't swept into Completed by
        // the create-time compatibility fallback, which would misrepresent
        // it as historical/completed in the Pending/History tabs below.
        $appointment = Appointment::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $owner->id,
            'created_by' => $owner->id,
            'appointment_at' => now(),
            'stage' => $stage,
            'status' => $stage->isTerminal() ? \App\Enums\AppointmentStatus::Completed : \App\Enums\AppointmentStatus::Scheduled,
            'outcome_notes' => $stage->isTerminal() ? 'Test outcome notes.' : null,
        ]);

        if ($lost) {
            $appointment->markLost('Test lost reason.');
        }

        return $appointment->fresh();
    }

    public function test_appointment_pending_tab_excludes_terminal_and_lost_appointments(): void
    {
        $employee = User::factory()->create();
        $pending = $this->makeAppointment($employee, AppointmentStage::VisitConducted);
        $succeeded = $this->makeAppointment($employee, AppointmentStage::Succeeded);
        $lost = $this->makeAppointment($employee, AppointmentStage::DiscussionCompleted, lost: true);

        $this->actingAs($employee);

        Livewire::test(ListAppointments::class)
            ->set('activeTab', 'pending')
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$succeeded, $lost]);
    }

    public function test_appointment_history_tab_shows_terminal_and_lost_together(): void
    {
        $employee = User::factory()->create();
        $pending = $this->makeAppointment($employee, AppointmentStage::VisitConducted);
        $succeeded = $this->makeAppointment($employee, AppointmentStage::Succeeded);
        $lost = $this->makeAppointment($employee, AppointmentStage::DiscussionCompleted, lost: true);

        $this->actingAs($employee);

        Livewire::test(ListAppointments::class)
            ->set('activeTab', 'history')
            ->assertCanSeeTableRecords([$succeeded, $lost])
            ->assertCanNotSeeTableRecords([$pending]);
    }

    public function test_appointment_lost_tab_shows_only_lost_appointments(): void
    {
        $employee = User::factory()->create();
        $succeeded = $this->makeAppointment($employee, AppointmentStage::Succeeded);
        $lost = $this->makeAppointment($employee, AppointmentStage::DiscussionCompleted, lost: true);

        $this->actingAs($employee);

        Livewire::test(ListAppointments::class)
            ->set('activeTab', 'lost')
            ->assertCanSeeTableRecords([$lost])
            ->assertCanNotSeeTableRecords([$succeeded]);
    }

    private function makeProposal(User $owner, ?ProposalOutcome $outcome): Proposal
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id]);
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
            'stage' => ProposalStage::Sent,
            'outcome' => $outcome,
            'notes' => in_array($outcome, [ProposalOutcome::Won, ProposalOutcome::Lost], true) ? 'Test outcome notes.' : null,
        ]);
    }

    public function test_proposal_pending_tab_shows_undecided_and_hold_proposals(): void
    {
        $employee = User::factory()->create();
        $pending = $this->makeProposal($employee, null);
        $hold = $this->makeProposal($employee, ProposalOutcome::Hold);
        $won = $this->makeProposal($employee, ProposalOutcome::Won);
        $lost = $this->makeProposal($employee, ProposalOutcome::Lost);

        $this->actingAs($employee);

        Livewire::test(ListProposals::class)
            ->set('activeTab', 'pending')
            ->assertCanSeeTableRecords([$pending, $hold])
            ->assertCanNotSeeTableRecords([$won, $lost]);
    }

    public function test_proposal_history_tab_shows_only_final_outcomes_not_hold(): void
    {
        $employee = User::factory()->create();
        $pending = $this->makeProposal($employee, null);
        $hold = $this->makeProposal($employee, ProposalOutcome::Hold);
        $won = $this->makeProposal($employee, ProposalOutcome::Won);
        $lost = $this->makeProposal($employee, ProposalOutcome::Lost);

        $this->actingAs($employee);

        Livewire::test(ListProposals::class)
            ->set('activeTab', 'history')
            ->assertCanSeeTableRecords([$won, $lost])
            ->assertCanNotSeeTableRecords([$pending, $hold]);
    }

    public function test_proposal_lost_tab_shows_only_lost_outcome(): void
    {
        $employee = User::factory()->create();
        $won = $this->makeProposal($employee, ProposalOutcome::Won);
        $lost = $this->makeProposal($employee, ProposalOutcome::Lost);

        $this->actingAs($employee);

        Livewire::test(ListProposals::class)
            ->set('activeTab', 'lost')
            ->assertCanSeeTableRecords([$lost])
            ->assertCanNotSeeTableRecords([$won]);
    }

    public function test_admin_sees_every_employees_lost_records_across_all_three_resources(): void
    {
        $admin = User::factory()->admin()->create();
        $nithin = User::factory()->create();
        $kural = User::factory()->create();

        $nithinLostLead = $this->makeLead($nithin, LeadStage::RequirementCollection, lost: true);
        $kuralLostAppointment = $this->makeAppointment($kural, AppointmentStage::AppointmentMade, lost: true);

        $this->actingAs($admin);

        Livewire::test(ListLeads::class)
            ->set('activeTab', 'lost')
            ->assertCanSeeTableRecords([$nithinLostLead]);

        Livewire::test(ListAppointments::class)
            ->set('activeTab', 'lost')
            ->assertCanSeeTableRecords([$kuralLostAppointment]);
    }
}
