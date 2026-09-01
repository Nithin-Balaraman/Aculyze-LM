<?php

namespace Tests\Feature;

use App\Enums\AppointmentStage;
use App\Enums\AppointmentStatus;
use App\Enums\LeadStage;
use App\Enums\LeadStatus;
use App\Models\Appointment;
use App\Models\Lead;
use App\Models\Prospect;
use App\Models\User;
use App\Services\WorkflowTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Phase 3 correction round 2: resolveCrossDropSource() previously mutated
 * the Appointment/Lead source directly inside PipelineBoard — an ordinary
 * business transition (it happens automatically as part of a normal user
 * cross-drop, not an administrator correcting historical data) that
 * PipelineBoard had no business deciding or persisting itself. Both
 * mutations are now owned by WorkflowTransitionService::
 * finalizeCrossDroppedAppointment()/finalizeCrossDroppedLead() — this
 * proves the service methods directly: correct normalized state, correct
 * legacy-compatibility echo, exactly one audit event, and a real
 * "already resolved" invariant that makes a second finalization attempt on
 * the same record impossible (not merely inconvenient).
 */
class WorkflowTransitionServiceCrossDropFinalizationTest extends TestCase
{
    use RefreshDatabase;

    private function makeAppointment(User $user): Appointment
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);

        return Appointment::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $user->id,
            'created_by' => $user->id,
            'appointment_at' => now()->addDay(),
            'stage' => AppointmentStage::AppointmentMade,
            'status' => AppointmentStatus::Scheduled,
        ]);
    }

    private function makeLead(User $user): Lead
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);

        return Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $user->id,
            'created_by' => $user->id,
            'stage' => LeadStage::RequirementCollection,
            'status' => LeadStatus::RequirementCollection,
            'temperature' => 'warm',
        ]);
    }

    public function test_finalize_cross_dropped_appointment_sets_normalized_state_legacy_echo_and_audit_event(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $appointment = $this->makeAppointment($user);

        app(WorkflowTransitionService::class)->finalizeCrossDroppedAppointment($appointment, ['outcome_notes' => 'Led to a real requirement.']);

        $appointment->refresh();
        $this->assertSame(AppointmentStage::Succeeded, $appointment->stage, 'Legacy stage is the intentionally-retained compatibility echo.');
        $this->assertSame(AppointmentStatus::Completed, $appointment->status, 'Normalized status is the authoritative write.');
        $this->assertSame('Led to a real requirement.', $appointment->outcome_notes);

        $this->assertDatabaseHas('audit_events', [
            'entity_type' => 'Appointment',
            'entity_id' => $appointment->id,
            'action' => 'appointment_resolved_via_cross_drop',
        ]);
        $this->assertSame(1, \App\Models\AuditEvent::query()
            ->where('entity_type', 'Appointment')
            ->where('entity_id', $appointment->id)
            ->where('action', 'appointment_resolved_via_cross_drop')
            ->count());
    }

    public function test_finalizing_an_already_resolved_appointment_is_rejected_and_never_double_finalizes(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $appointment = $this->makeAppointment($user);
        $service = app(WorkflowTransitionService::class);

        $service->finalizeCrossDroppedAppointment($appointment, ['outcome_notes' => 'First resolution.']);

        $this->expectException(LogicException::class);

        try {
            $service->finalizeCrossDroppedAppointment($appointment->fresh(), ['outcome_notes' => 'Second attempt.']);
        } finally {
            $this->assertSame(1, \App\Models\AuditEvent::query()
                ->where('entity_type', 'Appointment')
                ->where('entity_id', $appointment->id)
                ->where('action', 'appointment_resolved_via_cross_drop')
                ->count(), 'Finalization must be impossible to apply twice, not merely rare.');
            $this->assertSame('First resolution.', $appointment->fresh()->outcome_notes);
        }
    }

    public function test_finalize_cross_dropped_lead_sets_proposal_required_not_the_historical_backfill_mapping(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $lead = $this->makeLead($user);

        app(WorkflowTransitionService::class)->finalizeCrossDroppedLead($lead, ['notes' => 'Confirmed while creating the Proposal.']);

        $lead->refresh();
        $this->assertSame(LeadStage::Validated, $lead->stage, 'Legacy stage is the intentionally-retained compatibility echo.');
        $this->assertSame(LeadStatus::ProposalRequired, $lead->status, 'Must NOT be the conservative historical-backfill mapping (RequirementConfirmed).');
        $this->assertSame('Confirmed while creating the Proposal.', $lead->notes);

        $this->assertDatabaseHas('audit_events', [
            'entity_type' => 'Lead',
            'entity_id' => $lead->id,
            'action' => 'lead_resolved_via_cross_drop',
        ]);
    }

    public function test_finalizing_an_already_resolved_lead_is_rejected_and_never_double_finalizes(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $lead = $this->makeLead($user);
        $service = app(WorkflowTransitionService::class);

        $service->finalizeCrossDroppedLead($lead, ['notes' => 'First resolution.']);

        $this->expectException(LogicException::class);

        try {
            $service->finalizeCrossDroppedLead($lead->fresh(), ['notes' => 'Second attempt.']);
        } finally {
            $this->assertSame(1, \App\Models\AuditEvent::query()
                ->where('entity_type', 'Lead')
                ->where('entity_id', $lead->id)
                ->where('action', 'lead_resolved_via_cross_drop')
                ->count(), 'Finalization must be impossible to apply twice, not merely rare.');
            $this->assertSame('First resolution.', $lead->fresh()->notes);
        }
    }
}
