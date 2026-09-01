<?php

namespace Tests\Feature;

use App\Enums\CallOutcome;
use App\Enums\LeadStage;
use App\Enums\ProposalStage;
use App\Exceptions\EmployeeDeletionFailedException;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\Appointment;
use App\Models\CallRecord;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\Prospect;
use App\Models\User;
use App\Services\EmployeeDeletionService;
use Filament\Support\Exceptions\Halt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * UX Fixes Batch Issue 5: the guided employee-deletion workflow replacing
 * the old User DeletionGuard dead end. App\Services\EmployeeDeletionService
 * holds the actual logic; this exercises it both directly and through the
 * Filament action wired into UserResource.
 */
class EmployeeDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function service(): EmployeeDeletionService
    {
        return app(EmployeeDeletionService::class);
    }

    /**
     * One of every record type, all genuinely owned by $employee.
     *
     * @return array{prospect: Prospect, call: CallRecord, followUp: FollowUp, appointment: Appointment, lead: Lead, proposal: Proposal}
     */
    private function makeFullOwnershipSet(User $employee): array
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);

        $call = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $employee->id,
            'called_at' => now(),
            'outcome' => CallOutcome::CallbackRequested,
            'notes' => 'Asked to call back later.',
        ]);
        $followUp = $call->fresh()->followUp;

        $appointment = Appointment::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'appointment_at' => now(),
            'stage' => 'appointment_made',
        ]);

        $lead = Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => LeadStage::Validated,
            'temperature' => 'hot',
            'notes' => 'Validated in test fixture.',
        ]);

        $proposal = Proposal::create([
            'lead_id' => $lead->id,
            'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => ProposalStage::BeingPrepared,
        ]);

        return compact('prospect', 'call', 'followUp', 'appointment', 'lead', 'proposal');
    }

    // --- 1. No dependencies ---

    public function test_employee_with_no_dependencies_deletes_immediately_with_an_empty_form(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->create();
        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->mountTableAction('delete', $employee)
            ->assertTableActionDataSet([]);

        Livewire::test(ListUsers::class)
            ->callTableAction('delete', $employee)
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseMissing('users', ['id' => $employee->id]);
    }

    // --- 2. Dependency counts ---

    public function test_dependency_breakdown_reports_accurate_itemized_counts(): void
    {
        $employee = User::factory()->create();
        $this->makeFullOwnershipSet($employee);

        $breakdown = $this->service()->dependencyBreakdown($employee);

        $this->assertSame([
            'prospects' => 1,
            'callRecords' => 1,
            'followUps' => 1,
            'appointments' => 1,
            'leads' => 1,
            'proposals' => 1,
            'directReports' => 0,
        ], $breakdown);
    }

    public function test_dependency_breakdown_does_not_count_another_employees_records(): void
    {
        $employee = User::factory()->create();
        $other = User::factory()->create();
        $this->makeFullOwnershipSet($other);

        $breakdown = $this->service()->dependencyBreakdown($employee);

        $this->assertSame(0, array_sum($breakdown));
    }

    // --- 11. The reported "Jisha" scenario, as a permanent regression test ---

    public function test_a_follow_up_belonging_to_a_different_employee_is_never_counted_against_the_target(): void
    {
        $target = User::factory()->create(['name' => 'Jisha']);
        $otherEmployee = User::factory()->create(['name' => 'Other']);

        // The Prospect belongs to someone else, but the target makes the
        // call — CallRoutingService attributes the auto-created FollowUp to
        // the Prospect's assignee (otherEmployee), not the caller.
        $prospect = Prospect::factory()->create([
            'assigned_to' => $otherEmployee->id,
            'created_by' => $otherEmployee->id,
        ]);
        $call = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $target->id,
            'called_at' => now(),
            'outcome' => CallOutcome::CallbackRequested,
            'notes' => 'Asked to call back later.',
        ]);
        $followUp = $call->fresh()->followUp;

        $this->assertSame($otherEmployee->id, $followUp->user_id);
        $this->assertSame(0, $this->service()->dependencyBreakdown($target)['followUps']);
        $this->assertSame(1, $this->service()->dependencyBreakdown($otherEmployee)['followUps']);
    }

    // --- 3. Replacement required when CallRecords exist ---

    public function test_replacement_is_required_when_employee_has_call_records(): void
    {
        $employee = User::factory()->create();
        $this->makeFullOwnershipSet($employee);

        $this->assertTrue($this->service()->requiresReplacement($employee));

        $this->expectException(EmployeeDeletionFailedException::class);
        $this->service()->reassignAndDelete($employee, null);
    }

    public function test_replacement_not_required_when_employee_has_no_call_records_or_orphaned_creatorship(): void
    {
        $employee = User::factory()->create();
        $someoneElse = User::factory()->create();
        // Assigned to the employee but created by someone else, so there's
        // no created_by fallout from unassigning it either.
        Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $someoneElse->id]);

        $this->assertFalse($this->service()->requiresReplacement($employee));

        $this->service()->reassignAndDelete($employee, null);

        $this->assertDatabaseMissing('users', ['id' => $employee->id]);
    }

    public function test_replacement_is_required_when_the_employee_both_created_and_is_assigned_the_prospect(): void
    {
        // The Prospect survives (unassigned/soft-deleted) rather than being
        // hard-deleted, so if the employee is also its creator, that
        // created_by FK needs somewhere to go too.
        $employee = User::factory()->create();
        Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);

        $this->assertTrue($this->service()->requiresReplacement($employee));
    }

    // --- 4. Target cannot replace themselves ---

    public function test_target_employee_cannot_be_their_own_replacement(): void
    {
        $employee = User::factory()->create();
        $this->makeFullOwnershipSet($employee);

        $this->expectException(EmployeeDeletionFailedException::class);
        $this->service()->reassignAndDelete($employee, $employee);
    }

    // --- Invalid/stale replacement ---

    public function test_a_replacement_that_no_longer_exists_is_rejected(): void
    {
        $employee = User::factory()->create();
        $this->makeFullOwnershipSet($employee);
        $replacement = User::factory()->create();
        $replacement->delete();

        $this->expectException(EmployeeDeletionFailedException::class);
        $this->service()->reassignAndDelete($employee, $replacement);

        $this->assertDatabaseHas('users', ['id' => $employee->id]);
    }

    // --- 5 & 6. Option A ---

    public function test_option_a_keeps_and_unassigns_prospects_and_deletes_other_owned_records(): void
    {
        $employee = User::factory()->create();
        $replacement = User::factory()->create();
        $set = $this->makeFullOwnershipSet($employee);

        $this->service()->reassignAndDelete($employee, $replacement);

        $this->assertDatabaseHas('prospects', ['id' => $set['prospect']->id, 'assigned_to' => null]);
        $this->assertNull($set['prospect']->fresh()->deleted_at);

        $this->assertDatabaseMissing('follow_ups', ['id' => $set['followUp']->id]);
        $this->assertDatabaseMissing('appointments', ['id' => $set['appointment']->id]);
        $this->assertDatabaseMissing('leads', ['id' => $set['lead']->id]);
        $this->assertDatabaseMissing('proposals', ['id' => $set['proposal']->id]);
        $this->assertDatabaseMissing('users', ['id' => $employee->id]);
    }

    // --- 7. Option B ---

    public function test_option_b_soft_deletes_prospects_without_force_delete(): void
    {
        $employee = User::factory()->create();
        $replacement = User::factory()->create();
        $set = $this->makeFullOwnershipSet($employee);

        $this->service()->deleteEverything($employee, $replacement);

        $prospect = Prospect::withTrashed()->find($set['prospect']->id);
        $this->assertNotNull($prospect);
        $this->assertNotNull($prospect->deleted_at);

        $this->assertDatabaseMissing('follow_ups', ['id' => $set['followUp']->id]);
        $this->assertDatabaseMissing('appointments', ['id' => $set['appointment']->id]);
        $this->assertDatabaseMissing('leads', ['id' => $set['lead']->id]);
        $this->assertDatabaseMissing('proposals', ['id' => $set['proposal']->id]);
        $this->assertDatabaseMissing('users', ['id' => $employee->id]);
    }

    // --- 8 & 9. Both options retain and reassign Call Records ---

    public function test_option_a_reassigns_call_records_to_the_replacement(): void
    {
        $employee = User::factory()->create();
        $replacement = User::factory()->create();
        $set = $this->makeFullOwnershipSet($employee);

        $this->service()->reassignAndDelete($employee, $replacement);

        $this->assertDatabaseHas('call_records', ['id' => $set['call']->id, 'user_id' => $replacement->id]);
    }

    public function test_option_b_reassigns_call_records_to_the_replacement(): void
    {
        $employee = User::factory()->create();
        $replacement = User::factory()->create();
        $set = $this->makeFullOwnershipSet($employee);

        $this->service()->deleteEverything($employee, $replacement);

        $this->assertDatabaseHas('call_records', ['id' => $set['call']->id, 'user_id' => $replacement->id]);
    }

    public function test_replacement_may_be_an_admin(): void
    {
        $employee = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $set = $this->makeFullOwnershipSet($employee);

        $this->service()->reassignAndDelete($employee, $admin);

        $this->assertDatabaseHas('call_records', ['id' => $set['call']->id, 'user_id' => $admin->id]);
    }

    // --- Orphaned creatorship: created_by reassigned without touching someone else's record ---

    public function test_a_record_merely_created_by_the_employee_but_assigned_to_someone_else_is_not_deleted_only_its_creatorship_moves(): void
    {
        $employee = User::factory()->create();
        $owner = User::factory()->create();
        $replacement = User::factory()->create();

        $prospect = Prospect::factory()->create([
            'assigned_to' => $owner->id,
            'created_by' => $employee->id,
        ]);
        // Needs a Call Record to force requiresReplacement()/give the
        // employee something that actually triggers the guided flow.
        CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $employee->id,
            'called_at' => now(),
            'outcome' => CallOutcome::NoAnswer,
        ]);

        $this->assertTrue($this->service()->requiresReplacement($employee));

        $this->service()->reassignAndDelete($employee, $replacement);

        $prospect->refresh();
        $this->assertSame($owner->id, $prospect->assigned_to);
        $this->assertSame($replacement->id, $prospect->created_by);
        $this->assertDatabaseMissing('users', ['id' => $employee->id]);
    }

    // --- 10. Cancel performs no writes ---

    public function test_opening_the_dialog_without_confirming_performs_no_writes(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->create();
        $this->makeFullOwnershipSet($employee);

        $this->actingAs($admin);

        Livewire::test(ListUsers::class)->mountTableAction('delete', $employee);

        $this->assertDatabaseHas('users', ['id' => $employee->id]);
        $this->assertSame(1, Prospect::where('assigned_to', $employee->id)->count());
    }

    // --- 12. Authorization ---

    public function test_employee_cannot_access_the_employee_list_at_all(): void
    {
        // UserResource::canAccess() is admin-only, so a non-admin can't
        // even reach the page the guided delete action lives on.
        $employee = User::factory()->create();

        $this->actingAs($employee)->get('/admin/users')->assertForbidden();
    }

    public function test_run_guided_deletion_refuses_to_act_for_a_non_admin_even_if_called_directly(): void
    {
        $employee = User::factory()->create();
        $target = User::factory()->create();
        $this->actingAs($employee);

        $method = new \ReflectionMethod(UserResource::class, 'runGuidedDeletion');
        $method->setAccessible(true);

        $this->expectException(Halt::class);
        $method->invoke(null, $target, []);
    }

    // --- Self-delete / last-admin protection ---

    public function test_admin_cannot_delete_their_own_account(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->callTableAction('delete', $admin)
            ->assertTableActionHalted('delete')
            ->assertNotified("Can't delete {$admin->name}");

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_the_last_remaining_admin_is_flagged_as_undeletable(): void
    {
        // Deleting an employee always requires acting as an admin, which
        // would make the only admin its own acting user (self-delete) — so
        // this exercises the "last admin" rule directly, the same way
        // runGuidedDeletion() consults it before ever touching the DB.
        $onlyAdmin = User::factory()->admin()->create();

        $reason = new \ReflectionMethod(UserResource::class, 'selfOrLastAdminBlockReason');
        $reason->setAccessible(true);

        $this->assertNotNull($reason->invoke(null, $onlyAdmin));

        // With a second admin present, neither is "the last admin" anymore.
        User::factory()->admin()->create();
        $this->assertNull($reason->invoke(null, $onlyAdmin->fresh()));
    }

    // --- 13. Transaction rollback ---

    public function test_a_lead_owned_by_the_employee_with_a_proposal_owned_by_someone_else_rolls_back_cleanly(): void
    {
        $employee = User::factory()->create();
        $otherEmployee = User::factory()->create();
        $replacement = User::factory()->create();

        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);
        $lead = Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => LeadStage::Validated,
            'temperature' => 'hot',
            'notes' => 'Validated in test fixture.',
        ]);
        // The Proposal has since been reassigned to someone else, but the
        // Lead itself is still the target employee's.
        $proposal = Proposal::create([
            'lead_id' => $lead->id,
            'prospect_id' => $prospect->id,
            'assigned_to' => $otherEmployee->id,
            'created_by' => $otherEmployee->id,
            'stage' => ProposalStage::BeingPrepared,
        ]);
        CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $employee->id,
            'called_at' => now(),
            'outcome' => CallOutcome::NoAnswer,
        ]);

        $this->expectException(EmployeeDeletionFailedException::class);

        try {
            $this->service()->reassignAndDelete($employee, $replacement);
        } finally {
            // Nothing should have changed — full rollback.
            $this->assertDatabaseHas('users', ['id' => $employee->id]);
            $this->assertDatabaseHas('leads', ['id' => $lead->id, 'assigned_to' => $employee->id]);
            $this->assertDatabaseHas('proposals', ['id' => $proposal->id, 'assigned_to' => $otherEmployee->id]);
            $this->assertDatabaseHas('call_records', ['prospect_id' => $prospect->id, 'user_id' => $employee->id]);
        }
    }

    // --- Full guided flow through the Filament action ---

    public function test_full_guided_flow_reassign_option_through_the_table_action(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->create();
        $replacement = User::factory()->create();
        $set = $this->makeFullOwnershipSet($employee);

        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->callTableAction('delete', $employee, data: [
                'option' => 'reassign',
                'replacement_id' => $replacement->id,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseMissing('users', ['id' => $employee->id]);
        $this->assertDatabaseHas('prospects', ['id' => $set['prospect']->id, 'assigned_to' => null]);
        $this->assertDatabaseHas('call_records', ['id' => $set['call']->id, 'user_id' => $replacement->id]);
    }

    public function test_full_guided_flow_delete_everything_option_through_the_edit_page(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->create();
        $replacement = User::factory()->create();
        $set = $this->makeFullOwnershipSet($employee);

        $this->actingAs($admin);

        Livewire::test(EditUser::class, ['record' => $employee->getRouteKey()])
            ->callAction('delete', data: [
                'option' => 'delete_everything',
                'replacement_id' => $replacement->id,
            ]);

        $this->assertDatabaseMissing('users', ['id' => $employee->id]);
        $this->assertNotNull(Prospect::withTrashed()->find($set['prospect']->id)->deleted_at);
        $this->assertDatabaseHas('call_records', ['id' => $set['call']->id, 'user_id' => $replacement->id]);
    }

    public function test_guided_flow_without_choosing_a_replacement_is_rejected_by_form_validation(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->create();
        $this->makeFullOwnershipSet($employee);

        $this->actingAs($admin);

        // replacement_id is a required field whenever requiresReplacement()
        // is true, so this never even reaches the action closure — Filament
        // rejects it as a form validation error first.
        Livewire::test(ListUsers::class)
            ->callTableAction('delete', $employee, data: ['option' => 'reassign'])
            ->assertHasTableActionErrors(['replacement_id' => 'required']);

        $this->assertDatabaseHas('users', ['id' => $employee->id]);
    }

    /**
     * Defense in depth for the same rule, at the service layer directly —
     * in case anything ever calls EmployeeDeletionService without going
     * through the form's own validation.
     */
    public function test_service_layer_rejects_a_missing_replacement_independently_of_form_validation(): void
    {
        $employee = User::factory()->create();
        $this->makeFullOwnershipSet($employee);

        $this->expectException(EmployeeDeletionFailedException::class);
        $this->expectExceptionMessage('Choose who should take over their Call Records before continuing.');

        $this->service()->reassignAndDelete($employee, null);
    }
}
