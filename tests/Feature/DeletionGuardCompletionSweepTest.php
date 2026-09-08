<?php

namespace Tests\Feature;

use App\Enums\CallOutcome;
use App\Enums\DemoMode;
use App\Enums\LeadStage;
use App\Filament\Resources\AppointmentResource\Pages\EditAppointment;
use App\Filament\Resources\AppointmentResource\Pages\ListAppointments;
use App\Filament\Resources\DemoResource\Pages\EditDemo;
use App\Filament\Resources\DemoResource\Pages\ListDemos;
use App\Filament\Resources\FollowUpResource\Pages\ListFollowUps;
use App\Filament\Resources\ProspectResource\Pages\EditProspect;
use App\Filament\Resources\ProspectResource\Pages\ListProspects;
use App\Models\Appointment;
use App\Models\CallRecord;
use App\Models\Demo;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Prospect;
use App\Models\User;
use App\Services\RescheduleService;
use App\Services\WorkflowTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

/**
 * Deletion-guard completion sweep: the delete surfaces audit fix pass 1
 * deliberately left out of scope and reported instead — Demo, Appointment
 * and Prospect — plus the Demo gap in User's organization-change guard.
 *
 * Each of the three reschedule chains (demos/appointments/follow_ups
 * .rescheduled_from_id) is a plain RESTRICT self-referencing foreign key,
 * so deleting the ORIGINAL of a rescheduled pair used to fail as a raw,
 * uncaught 500 — reproduced here before the guards were added.
 */
class DeletionGuardCompletionSweepTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{admin: User, employee: User} */
    private function people(): array
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->create();

        return compact('admin', 'employee');
    }

    private function leadFor(User $employee): Lead
    {
        $prospect = Prospect::factory()->create([
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
        ]);

        return Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => LeadStage::Validated,
            'temperature' => 'hot',
            'notes' => 'Validated in test fixture.',
        ]);
    }

    private function demoFor(User $employee, ?Lead $lead = null): Demo
    {
        $lead ??= $this->leadFor($employee);

        return app(WorkflowTransitionService::class)->transitionToDemo($lead, $lead, 'lead', [
            'demo_at' => now()->addDays(2),
            'mode' => DemoMode::OnSite,
            'location' => '14 Industrial Estate, Kochi',
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
        ]);
    }

    private function appointmentFor(User $employee): Appointment
    {
        $prospect = Prospect::factory()->create([
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
        ]);

        return Appointment::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'appointment_at' => now()->addDays(2),
            'stage' => 'appointment_made',
        ]);
    }

    // =================================================================
    // DEMO
    // =================================================================

    public function test_a_rescheduled_demo_blocks_deleting_the_demo_it_replaced(): void
    {
        ['employee' => $employee] = $this->people();
        $original = $this->demoFor($employee);

        $replacement = app(RescheduleService::class)->reschedule(
            $original,
            ['demo_at' => now()->addDays(5)],
            'Customer asked to move it.',
        );

        $this->assertSame($original->id, $replacement->rescheduled_from_id);
        $this->assertSame(['replacement Demo' => 1], $original->fresh()->deletionBlockers());
        $this->assertSame(['replacement Demo' => 0], $replacement->fresh()->deletionBlockers());
    }

    public function test_demo_row_delete_is_blocked_and_keeps_both_demos(): void
    {
        ['admin' => $admin, 'employee' => $employee] = $this->people();
        $original = $this->demoFor($employee);
        $replacement = app(RescheduleService::class)->reschedule($original, ['demo_at' => now()->addDays(5)]);

        $this->actingAs($admin);

        // A rescheduled original sits on the History tab, not Pending.
        Livewire::test(ListDemos::class)
            ->set('activeTab', 'history')
            ->callTableAction('delete', $original)
            ->assertNotified("Can't delete this demo");

        $this->assertDatabaseHas('demos', ['id' => $original->id]);
        $this->assertDatabaseHas('demos', ['id' => $replacement->id]);
    }

    public function test_demo_edit_page_delete_is_blocked(): void
    {
        ['admin' => $admin, 'employee' => $employee] = $this->people();
        $original = $this->demoFor($employee);
        app(RescheduleService::class)->reschedule($original, ['demo_at' => now()->addDays(5)]);

        $this->actingAs($admin);

        Livewire::test(EditDemo::class, ['record' => $original->getRouteKey()])
            ->callAction('delete')
            ->assertNotified("Can't delete this demo");

        $this->assertDatabaseHas('demos', ['id' => $original->id]);
    }

    public function test_demo_bulk_delete_is_blocked_entirely(): void
    {
        ['admin' => $admin, 'employee' => $employee] = $this->people();
        $original = $this->demoFor($employee);
        $replacement = app(RescheduleService::class)->reschedule($original, ['demo_at' => now()->addDays(5)]);
        $unrelated = $this->demoFor($employee);

        $this->actingAs($admin);

        Livewire::test(ListDemos::class)
            ->set('activeTab', 'history')
            ->callTableBulkAction('delete', [$original, $unrelated])
            ->assertNotified("Can't delete 1 of the selected demos");

        // Established convention: the whole batch is blocked, never a
        // silent partial delete.
        $this->assertDatabaseHas('demos', ['id' => $original->id]);
        $this->assertDatabaseHas('demos', ['id' => $replacement->id]);
        $this->assertDatabaseHas('demos', ['id' => $unrelated->id]);
    }

    public function test_a_demo_that_replaced_nothing_is_still_deletable(): void
    {
        ['admin' => $admin, 'employee' => $employee] = $this->people();
        $demo = $this->demoFor($employee);

        $this->actingAs($admin);

        Livewire::test(ListDemos::class)
            ->callTableAction('delete', $demo);

        $this->assertDatabaseMissing('demos', ['id' => $demo->id]);
    }

    // =================================================================
    // APPOINTMENT
    // =================================================================

    public function test_a_rescheduled_appointment_blocks_deleting_the_one_it_replaced(): void
    {
        ['employee' => $employee] = $this->people();
        $original = $this->appointmentFor($employee);

        $replacement = app(RescheduleService::class)->reschedule(
            $original,
            ['appointment_at' => now()->addDays(5)],
            'Customer asked to move it.',
        );

        $this->assertSame($original->id, $replacement->rescheduled_from_id);
        $this->assertSame(['replacement Appointment' => 1], $original->fresh()->deletionBlockers());
    }

    public function test_appointment_row_and_edit_delete_are_blocked(): void
    {
        ['admin' => $admin, 'employee' => $employee] = $this->people();
        $original = $this->appointmentFor($employee);
        $replacement = app(RescheduleService::class)->reschedule($original, ['appointment_at' => now()->addDays(5)]);

        $this->actingAs($admin);

        Livewire::test(ListAppointments::class)
            ->set('activeTab', 'history')
            ->callTableAction('delete', $original)
            ->assertNotified("Can't delete this appointment");

        Livewire::test(EditAppointment::class, ['record' => $original->getRouteKey()])
            ->callAction('delete')
            ->assertNotified("Can't delete this appointment");

        $this->assertDatabaseHas('appointments', ['id' => $original->id]);
        $this->assertDatabaseHas('appointments', ['id' => $replacement->id]);
    }

    public function test_appointment_bulk_delete_is_blocked_entirely(): void
    {
        ['admin' => $admin, 'employee' => $employee] = $this->people();
        $original = $this->appointmentFor($employee);
        app(RescheduleService::class)->reschedule($original, ['appointment_at' => now()->addDays(5)]);
        $unrelated = $this->appointmentFor($employee);

        $this->actingAs($admin);

        Livewire::test(ListAppointments::class)
            ->set('activeTab', 'history')
            ->callTableBulkAction('delete', [$original, $unrelated])
            ->assertNotified("Can't delete 1 of the selected appointments");

        $this->assertDatabaseHas('appointments', ['id' => $original->id]);
        $this->assertDatabaseHas('appointments', ['id' => $unrelated->id]);
    }

    public function test_an_appointment_that_replaced_nothing_is_still_deletable(): void
    {
        ['admin' => $admin, 'employee' => $employee] = $this->people();
        $appointment = $this->appointmentFor($employee);

        $this->actingAs($admin);

        Livewire::test(ListAppointments::class)
            ->callTableAction('delete', $appointment);

        $this->assertDatabaseMissing('appointments', ['id' => $appointment->id]);
    }

    // =================================================================
    // FOLLOW-UP — the same reschedule-chain gap, found during this sweep
    // =================================================================

    public function test_a_rescheduled_follow_up_blocks_deleting_the_one_it_replaced(): void
    {
        ['admin' => $admin, 'employee' => $employee] = $this->people();
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
        $original = FollowUp::create([
            'prospect_id' => $prospect->id,
            'user_id' => $employee->id,
            'follow_up_at' => now()->addDay(),
            'reason' => 'Asked to call back.',
        ]);

        $replacement = app(RescheduleService::class)->reschedule($original, ['follow_up_at' => now()->addDays(4)]);

        $this->assertSame($original->id, $replacement->rescheduled_from_id);

        $this->actingAs($admin);

        Livewire::test(ListFollowUps::class)
            ->set('activeTab', 'history')
            ->callTableAction('delete', $original)
            ->assertNotified("Can't delete this follow-up");

        $this->assertDatabaseHas('follow_ups', ['id' => $original->id]);
        $this->assertDatabaseHas('follow_ups', ['id' => $replacement->id]);
    }

    // =================================================================
    // PROSPECT — soft delete, so RESTRICT is never reached
    // =================================================================

    public function test_deleting_a_prospect_with_downstream_history_soft_deletes_and_keeps_everything(): void
    {
        ['admin' => $admin, 'employee' => $employee] = $this->people();
        $lead = $this->leadFor($employee);
        $prospect = $lead->prospect;
        $call = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $employee->id,
            'called_at' => now(),
            'outcome' => CallOutcome::NoAnswer,
        ]);
        $demo = $this->demoFor($employee, $lead);

        $this->actingAs($admin);

        Livewire::test(ListProspects::class)
            ->callTableAction('delete', $prospect)
            ->assertHasNoTableActionErrors();

        // Prospect is the one soft-deleted model in this schema, so its
        // Delete never issues a hard DELETE and never reaches any of the
        // six RESTRICT foreign keys pointing at prospects. The row itself
        // physically remains, as does every downstream record.
        $this->assertNotNull(Prospect::withTrashed()->find($prospect->id)->deleted_at);
        $this->assertDatabaseHas('leads', ['id' => $lead->id]);
        $this->assertDatabaseHas('call_records', ['id' => $call->id]);
        $this->assertDatabaseHas('demos', ['id' => $demo->id]);
    }

    public function test_bulk_deleting_prospects_with_history_also_only_soft_deletes(): void
    {
        ['admin' => $admin, 'employee' => $employee] = $this->people();
        $lead = $this->leadFor($employee);
        $prospect = $lead->prospect;

        $this->actingAs($admin);

        Livewire::test(ListProspects::class)
            ->callTableBulkAction('delete', [$prospect]);

        $this->assertNotNull(Prospect::withTrashed()->find($prospect->id)->deleted_at);
        $this->assertDatabaseHas('leads', ['id' => $lead->id]);
    }

    public function test_prospect_edit_page_delete_also_only_soft_deletes(): void
    {
        ['admin' => $admin, 'employee' => $employee] = $this->people();
        $lead = $this->leadFor($employee);
        $prospect = $lead->prospect;

        $this->actingAs($admin);

        Livewire::test(EditProspect::class, ['record' => $prospect->getRouteKey()])
            ->callAction('delete');

        $this->assertNotNull(Prospect::withTrashed()->find($prospect->id)->deleted_at);
        $this->assertDatabaseHas('leads', ['id' => $lead->id]);
    }

    // =================================================================
    // ORGANIZATION-CHANGE GUARD — Demo ownership
    // =================================================================

    public function test_moving_a_user_who_owns_a_demo_to_another_organization_is_blocked(): void
    {
        // A Senior Manager: no manager_id and no direct reports, so the
        // record-ownership rule is the only thing this exercises. The
        // Prospect and Lead deliberately belong to someone else, so the
        // Demo is the ONLY thing this user owns — otherwise the guard's
        // pre-existing `leads` entry would pass this test without Demo
        // ever being consulted.
        $mover = User::factory()->admin()->create();
        $someoneElse = User::factory()->create();
        $lead = $this->leadFor($someoneElse);

        app(WorkflowTransitionService::class)->transitionToDemo($lead, $lead, 'lead', [
            'demo_at' => now()->addDay(),
            'mode' => DemoMode::OnSite,
            'location' => 'Site A',
            'assigned_to' => $mover->id,
            'created_by' => $someoneElse->id,
        ]);

        $owner = $mover;
        $destination = Organization::factory()->create();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('records in the previous organization are still assigned to or created by this user');

        $owner->organization_id = $destination->id;
        $owner->save();
    }

    public function test_moving_a_user_who_merely_created_a_demo_is_also_blocked(): void
    {
        // Again isolated: the creator owns nothing but this Demo's
        // created_by.
        $owner = User::factory()->create();
        $creator = User::factory()->admin()->create();
        $lead = $this->leadFor($owner);

        app(WorkflowTransitionService::class)->transitionToDemo($lead, $lead, 'lead', [
            'demo_at' => now()->addDay(),
            'mode' => DemoMode::OnSite,
            'location' => 'Site B',
            'assigned_to' => $owner->id,
            'created_by' => $creator->id,
        ]);

        $destination = Organization::factory()->create();

        $this->expectException(LogicException::class);

        $creator->organization_id = $destination->id;
        $creator->save();
    }

    public function test_a_user_owning_no_records_can_still_be_moved(): void
    {
        $mover = User::factory()->admin()->create();
        $destination = Organization::factory()->create();

        $mover->organization_id = $destination->id;
        $mover->save();

        $this->assertDatabaseHas('users', ['id' => $mover->id, 'organization_id' => $destination->id]);
    }
}
