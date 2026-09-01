<?php

namespace Tests\Feature;

use App\Enums\AppointmentOutcome;
use App\Enums\AppointmentStage;
use App\Enums\AppointmentStatus;
use App\Enums\FollowUpStatus;
use App\Enums\LeadStatus;
use App\Models\Appointment;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Prospect;
use App\Models\User;
use App\Services\WorkflowTransitionService;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 2: WorkflowTransitionService's Appointment-outcome branches not
 * already covered by AppointmentRescheduleTest/DemoModelTest — Follow-Up
 * Required and Requirement Identified. Only RequirementIdentified ever
 * creates/moves to a Lead; every other outcome must not.
 */
class WorkflowTransitionServiceTest extends TestCase
{
    use RefreshDatabase;

    private function newAppointment(User $user): Appointment
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);

        return Appointment::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $user->id,
            'created_by' => $user->id,
            'appointment_at' => now()->addDays(2),
            'stage' => AppointmentStage::AppointmentMade,
            'status' => AppointmentStatus::Scheduled,
        ]);
    }

    public function test_follow_up_required_creates_a_follow_up_linked_via_origin(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $appointment = $this->newAppointment($user);

            app(WorkflowTransitionService::class)->transitionAppointmentOutcome(
                $appointment, AppointmentOutcome::FollowUpRequired, [
                    'follow_up_at' => now()->addDays(3),
                    'reason' => 'Send more info',
                    'outcome_notes' => 'Needs more information before deciding.',
                ]
            );

            $followUp = \App\Models\FollowUp::query()->where('prospect_id', $appointment->prospect_id)->firstOrFail();
            $this->assertSame(FollowUpStatus::Pending, $followUp->status);
            $this->assertSame('appointment', $followUp->origin_type);
            $this->assertSame($appointment->id, $followUp->origin_id);
            $this->assertSame(0, Lead::query()->where('prospect_id', $appointment->prospect_id)->count());
        });
    }

    public function test_requirement_identified_is_the_only_outcome_that_creates_a_lead(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $appointment = $this->newAppointment($user);

            app(WorkflowTransitionService::class)->transitionAppointmentOutcome(
                $appointment, AppointmentOutcome::RequirementIdentified, ['outcome_notes' => 'Requirement identified during the visit.']
            );

            $lead = Lead::query()->where('prospect_id', $appointment->prospect_id)->first();
            $this->assertNotNull($lead);
            $this->assertSame(LeadStatus::RequirementCollection, $lead->status);
        });
    }

    public function test_no_current_requirement_creates_no_downstream_record(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $appointment = $this->newAppointment($user);

            app(WorkflowTransitionService::class)->transitionAppointmentOutcome(
                $appointment, AppointmentOutcome::NoCurrentRequirement, ['outcome_notes' => 'No requirement at this time.']
            );

            $this->assertSame(0, Lead::query()->where('prospect_id', $appointment->prospect_id)->count());
            $this->assertSame(0, \App\Models\FollowUp::query()->where('prospect_id', $appointment->prospect_id)->count());
        });
    }

    public function test_hierarchy_and_tenant_isolation_remain_enforced_by_the_transition_service(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $manager = User::factory()->create(['organization_id' => $org->id, 'role' => \App\Enums\UserRole::Manager]);
            $employee = User::factory()->create(['organization_id' => $org->id, 'role' => \App\Enums\UserRole::Employee, 'manager_id' => $manager->id]);
            $otherEmployee = User::factory()->create(['organization_id' => $org->id, 'role' => \App\Enums\UserRole::Employee]);
            $appointment = $this->newAppointment($employee);

            app(WorkflowTransitionService::class)->transitionAppointmentOutcome(
                $appointment, AppointmentOutcome::RequirementIdentified, ['outcome_notes' => 'Requirement identified during the visit.']
            );

            $lead = Lead::query()->where('prospect_id', $appointment->prospect_id)->firstOrFail();

            $this->assertTrue(Lead::query()->visibleTo($manager)->whereKey($lead->id)->exists());
            $this->assertFalse(Lead::query()->visibleTo($otherEmployee)->whereKey($lead->id)->exists());
        });
    }
}
