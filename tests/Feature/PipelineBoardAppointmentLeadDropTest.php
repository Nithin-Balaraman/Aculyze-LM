<?php

namespace Tests\Feature;

use App\Enums\AppointmentStage;
use App\Enums\AppointmentStatus;
use App\Enums\LeadStage;
use App\Enums\LeadStatus;
use App\Filament\Pages\PipelineBoard;
use App\Models\Appointment;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Prospect;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 3 correction round: dropAppointment()/dropLead()'s same-lane drag
 * handlers previously performed ordinary business transitions as raw
 * `$record->update(['stage' => ...])` calls, never touching normalized
 * status/going through WorkflowTransitionService. This proves the
 * corrected classification:
 *  - Appointment Not Succeeded -> reuses Appointment::markLost() (approved
 *    existing "final/lost handling", not a bespoke duplicate).
 *  - Appointment Succeeded -> disabled as a drag target entirely (no
 *    single unambiguous AppointmentOutcome), redirected to the Record
 *    Outcome action.
 *  - Lead RequirementCollection/Validated -> routed through
 *    WorkflowTransitionService::transitionLeadStatus(), with legacy
 *    `stage` kept only as a non-authoritative display-compatibility echo.
 *  - Lead DemoScheduledOrDone -> disabled as a drag target entirely
 *    (superseded by the dedicated Demo lane/action).
 */
class PipelineBoardAppointmentLeadDropTest extends TestCase
{
    use RefreshDatabase;

    private function invokePerformDrop(PipelineBoard $board, array $arguments, array $data = []): void
    {
        $method = new \ReflectionMethod($board, 'performDrop');
        $method->setAccessible(true);
        $method->invoke($board, $arguments, $data);
    }

    private function invokeIsDropEligible(PipelineBoard $board, array $arguments): bool
    {
        $method = new \ReflectionMethod($board, 'isDropEligible');
        $method->setAccessible(true);

        return $method->invoke($board, $arguments);
    }

    public function test_dragging_an_appointment_to_not_succeeded_reuses_mark_lost_and_leaves_status_alone(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $appointment = Appointment::create([
                'prospect_id' => $prospect->id,
                'assigned_to' => $user->id,
                'created_by' => $user->id,
                'appointment_at' => now()->addDay(),
                'stage' => AppointmentStage::AppointmentMade,
                'status' => AppointmentStatus::Scheduled,
            ]);

            $this->invokePerformDrop(
                app(PipelineBoard::class),
                ['resource' => 'appointment', 'id' => $appointment->id, 'stage' => AppointmentStage::NotSucceeded->value],
                ['outcome_notes' => 'Customer went with a competitor.']
            );

            $appointment->refresh();
            $this->assertTrue($appointment->is_lost);
            $this->assertSame('Customer went with a competitor.', $appointment->lost_reason);
            // markLost() never touches stage/status — this is the exact
            // same behavior as AppointmentResource's own "Mark Lost" row
            // action, not a bespoke stage=NotSucceeded mutation.
            $this->assertSame(AppointmentStage::AppointmentMade, $appointment->stage);
            $this->assertSame(AppointmentStatus::Scheduled, $appointment->status);
        });
    }

    public function test_dragging_an_appointment_onto_succeeded_is_not_eligible(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $appointment = Appointment::create([
                'prospect_id' => $prospect->id,
                'assigned_to' => $user->id,
                'created_by' => $user->id,
                'appointment_at' => now()->addDay(),
                'stage' => AppointmentStage::AppointmentMade,
                'status' => AppointmentStatus::Scheduled,
            ]);

            $board = app(PipelineBoard::class);
            $arguments = ['resource' => 'appointment', 'id' => $appointment->id, 'stage' => AppointmentStage::Succeeded->value];

            $this->assertFalse($this->invokeIsDropEligible($board, $arguments));

            $this->invokePerformDrop($board, $arguments, ['outcome_notes' => 'Went well.']);

            $appointment->refresh();
            $this->assertSame(AppointmentStage::AppointmentMade, $appointment->stage, 'Succeeded must never be reachable via raw drag.');
            $this->assertSame(AppointmentStatus::Scheduled, $appointment->status);
        });
    }

    public function test_dragging_a_lead_to_validated_routes_through_the_transition_service_and_syncs_legacy_stage(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $lead = Lead::create([
                'prospect_id' => $prospect->id,
                'assigned_to' => $user->id,
                'created_by' => $user->id,
                'stage' => LeadStage::RequirementCollection,
                'status' => LeadStatus::RequirementCollection,
                'temperature' => 'warm',
            ]);

            $this->invokePerformDrop(
                app(PipelineBoard::class),
                ['resource' => 'lead', 'id' => $lead->id, 'stage' => LeadStage::Validated->value],
                ['notes' => 'Confirmed the requirement and budget.']
            );

            $lead->refresh();
            $this->assertSame(LeadStatus::ProposalRequired, $lead->status, 'Normalized status is the authoritative write.');
            $this->assertSame(LeadStage::Validated, $lead->stage, 'Legacy stage is kept only as a display-compatibility echo.');
            $this->assertSame('Confirmed the requirement and budget.', $lead->notes);
        });
    }

    public function test_dragging_a_lead_to_validated_without_notes_is_rejected_and_leaves_the_lead_untouched(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $lead = Lead::create([
                'prospect_id' => $prospect->id,
                'assigned_to' => $user->id,
                'created_by' => $user->id,
                'stage' => LeadStage::RequirementCollection,
                'status' => LeadStatus::RequirementCollection,
                'temperature' => 'warm',
            ]);

            try {
                $this->invokePerformDrop(
                    app(PipelineBoard::class),
                    ['resource' => 'lead', 'id' => $lead->id, 'stage' => LeadStage::Validated->value],
                    ['notes' => '']
                );
                $this->fail('Expected a Halt exception for the missing Notes rejection.');
            } catch (\Filament\Support\Exceptions\Halt $e) {
                // expected
            }

            $lead->refresh();
            $this->assertSame(LeadStatus::RequirementCollection, $lead->status);
            $this->assertSame(LeadStage::RequirementCollection, $lead->stage);
        });
    }

    public function test_dragging_a_lead_onto_demo_scheduled_or_done_is_not_eligible(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $this->actingAs($user);
            $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
            $lead = Lead::create([
                'prospect_id' => $prospect->id,
                'assigned_to' => $user->id,
                'created_by' => $user->id,
                'stage' => LeadStage::RequirementCollection,
                'status' => LeadStatus::RequirementCollection,
                'temperature' => 'warm',
            ]);

            $board = app(PipelineBoard::class);
            $arguments = ['resource' => 'lead', 'id' => $lead->id, 'stage' => LeadStage::DemoScheduledOrDone->value];

            $this->assertFalse($this->invokeIsDropEligible($board, $arguments));

            $this->invokePerformDrop($board, $arguments, []);

            $lead->refresh();
            $this->assertSame(LeadStage::RequirementCollection, $lead->stage, 'DemoScheduledOrDone must never be reachable via raw drag.');
        });
    }
}
