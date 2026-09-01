<?php

namespace Tests\Feature;

use App\Enums\AppointmentOutcome;
use App\Enums\AppointmentStage;
use App\Enums\AppointmentStatus;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\Prospect;
use App\Models\User;
use App\Services\RescheduleService;
use App\Services\WorkflowTransitionService;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

/**
 * Phase 2: Appointment reschedule/history — same guarantees as
 * FollowUpRescheduleTest — plus the "repeat activity is not a reschedule"
 * distinction: Another Appointment Required marks the current Appointment
 * Completed (never Rescheduled) and links the new one via origin lineage,
 * never rescheduled_from_id.
 *
 * See FollowUpRescheduleTest's own docblock re: why every scoped-model
 * assertion runs inside Tenancy::runAs().
 */
class AppointmentRescheduleTest extends TestCase
{
    use RefreshDatabase;

    private function newAppointment(User $user): \App\Models\Appointment
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);

        return \App\Models\Appointment::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $user->id,
            'created_by' => $user->id,
            'appointment_at' => now()->addDays(2),
            'stage' => AppointmentStage::AppointmentMade,
            'status' => AppointmentStatus::Scheduled,
        ]);
    }

    public function test_reschedule_preserves_the_old_record_and_creates_a_distinct_active_replacement(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $original = $this->newAppointment($user);
            $newTime = now()->addDays(5)->startOfMinute();

            $replacement = app(RescheduleService::class)->reschedule($original, ['appointment_at' => $newTime], 'Client asked to push it back');

            $this->assertNotSame($original->id, $replacement->id);
            $this->assertSame(AppointmentStatus::Rescheduled, $original->fresh()->status);
            $this->assertSame(AppointmentStatus::Scheduled, $replacement->status);
            $this->assertTrue($newTime->equalTo($replacement->appointment_at));
            $this->assertNull($replacement->origin_type);
            $this->assertNull($original->fresh()->origin_type);
        });
    }

    public function test_direct_edit_cannot_change_an_already_set_appointment_at(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $original = $this->newAppointment($user);

            $this->expectException(LogicException::class);

            $original->update(['appointment_at' => now()->addDays(9)]);
        });
    }

    public function test_another_appointment_required_marks_the_original_completed_not_rescheduled(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $original = $this->newAppointment($user);

            app(WorkflowTransitionService::class)->transitionAppointmentOutcome(
                $original, AppointmentOutcome::AnotherAppointmentRequired, [
                    'appointment_at' => now()->addDays(3),
                    'outcome_notes' => 'Needs a second visit to finalize.',
                ]
            );

            $original->refresh();
            $this->assertSame(AppointmentStatus::Completed, $original->status);
            $this->assertNotSame(AppointmentStatus::Rescheduled, $original->status);
            $this->assertSame(AppointmentOutcome::AnotherAppointmentRequired, $original->outcome);
        });
    }

    public function test_another_appointment_required_creates_a_new_scheduled_appointment_via_lineage_not_reschedule_linkage(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $original = $this->newAppointment($user);
            $newAppointmentAt = now()->addDays(3)->startOfMinute();

            app(WorkflowTransitionService::class)->transitionAppointmentOutcome(
                $original, AppointmentOutcome::AnotherAppointmentRequired, [
                    'appointment_at' => $newAppointmentAt,
                    'outcome_notes' => 'Needs a second visit to finalize.',
                ]
            );

            $new = \App\Models\Appointment::query()
                ->where('prospect_id', $original->prospect_id)
                ->where('id', '!=', $original->id)
                ->firstOrFail();

            $this->assertSame(AppointmentStatus::Scheduled, $new->status);
            $this->assertNull($new->rescheduled_from_id);
            $this->assertSame('appointment', $new->origin_type);
            $this->assertSame($original->id, $new->origin_id);
            $this->assertTrue($newAppointmentAt->equalTo($new->appointment_at));
        });
    }

    public function test_the_appointments_table_actually_supports_origin_lineage_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('appointments', ['origin_type', 'origin_id', 'rescheduled_from_id']));
    }

    /**
     * Two independently meaningful failure modes, kept deliberately
     * separate so neither can accidentally pass for the other's reason
     * (both throw LogicException, so asserting on the message matters):
     * missing outcome_notes is rejected before the outcome/downstream is
     * ever resolved; a missing Lead for Demo/Proposal Required is a
     * separate, later failure once outcome_notes is valid.
     */
    public function test_completed_appointment_without_outcome_notes_is_rejected_for_missing_documentation(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $original = $this->newAppointment($user);

            try {
                app(WorkflowTransitionService::class)->transitionAppointmentOutcome(
                    $original, AppointmentOutcome::NoCurrentRequirement, [] // no outcome_notes -> throws
                );
                $this->fail('Expected missing outcome_notes to throw.');
            } catch (LogicException $e) {
                $this->assertStringContainsString('Outcome Notes', $e->getMessage());
            }

            $original->refresh();
            $this->assertSame(AppointmentStatus::Scheduled, $original->status);
            $this->assertNull($original->outcome);
        });
    }

    public function test_demo_required_with_valid_outcome_notes_but_missing_lead_is_rejected_for_missing_lead(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $original = $this->newAppointment($user);

            try {
                app(WorkflowTransitionService::class)->transitionAppointmentOutcome(
                    $original, AppointmentOutcome::DemoRequired, [
                        'outcome_notes' => 'Requirement is clear, wants to see the product.',
                    ] // valid outcome_notes, no lead_id -> throws for a DIFFERENT reason
                );
                $this->fail('Expected a missing lead_id to throw.');
            } catch (LogicException $e) {
                $this->assertStringContainsString(
                    'A Demo/Proposal transition requires an existing Lead (a valid requirement) — none was supplied.',
                    $e->getMessage()
                );
            }

            // The whole transaction rolls back on the Lead failure, so even
            // though outcome_notes passed validation, nothing persists.
            $original->refresh();
            $this->assertSame(AppointmentStatus::Scheduled, $original->status);
            $this->assertNull($original->outcome);
            $this->assertSame(0, AuditEvent::withoutGlobalScopes()->where('entity_type', 'Appointment')->where('action', 'appointment_outcome_recorded')->count());
        });
    }

    public function test_historical_rescheduled_appointment_cannot_be_silently_edited(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $original = $this->newAppointment($user);
            app(RescheduleService::class)->reschedule($original, ['appointment_at' => now()->addDays(4)]);

            $this->expectException(LogicException::class);

            $original->fresh()->update(['appointment_at' => now()->addDays(10)]);
        });
    }

    public function test_reassignment_still_works_on_a_completed_appointment(): void
    {
        $org = Organization::factory()->create();

        Tenancy::runAs($org->id, function () use ($org) {
            $user = User::factory()->create(['organization_id' => $org->id]);
            $other = User::factory()->create(['organization_id' => $org->id]);
            $original = $this->newAppointment($user);

            app(WorkflowTransitionService::class)->transitionAppointmentOutcome(
                $original, AppointmentOutcome::NoCurrentRequirement, ['outcome_notes' => 'No requirement at this time.']
            );

            // A field other than the schedule must remain freely writable
            // on a historical (Completed) record — the guard is scoped to
            // the schedule column only, never a blanket immutability lock.
            $original->fresh()->update(['assigned_to' => $other->id]);

            $this->assertSame($other->id, $original->fresh()->assigned_to);
        });
    }

    public function test_cross_tenant_appointment_transition_is_rejected(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $appointmentA = Tenancy::runAs($orgA->id, function () use ($orgA) {
            $user = User::factory()->create(['organization_id' => $orgA->id]);

            return $this->newAppointment($user);
        });

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Tenancy::runAs($orgB->id, fn () => app(WorkflowTransitionService::class)->transitionAppointmentOutcome(
            $appointmentA, AppointmentOutcome::NoCurrentRequirement, []
        ));
    }
}
