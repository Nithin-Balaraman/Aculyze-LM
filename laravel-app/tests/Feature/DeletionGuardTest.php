<?php

namespace Tests\Feature;

use App\Enums\CallOutcome;
use App\Enums\LeadStage;
use App\Enums\ProposalStage;
use App\Filament\Resources\CallRecordResource\Pages\ListCallRecords;
use App\Filament\Resources\LeadResource\Pages\EditLead;
use App\Filament\Resources\LeadResource\Pages\ListLeads;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\CallRecord;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Change Request Sections 5, 8, 9: every FK in this schema is a plain
 * RESTRICT constraint, so deleting a User/Call Record/Lead that still has
 * related records used to fail as a raw, uncaught 500 (root-caused in the
 * prior inspection report). App\Support\DeletionGuard now blocks these
 * up front with a friendly notification instead — no cascade-delete of real
 * business history, no silent soft-delete.
 */
class DeletionGuardTest extends TestCase
{
    use RefreshDatabase;

    // --- Section 5: User deletion ---

    public function test_admin_can_delete_a_user_with_no_related_records(): void
    {
        $admin = User::factory()->admin()->create();
        $emptyUser = User::factory()->create();

        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->callTableAction('delete', $emptyUser)
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseMissing('users', ['id' => $emptyUser->id]);
    }

    public function test_deleting_a_user_with_related_records_is_blocked_with_a_friendly_notification(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->create();
        Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);

        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->callTableAction('delete', $employee)
            ->assertTableActionHalted('delete')
            ->assertNotified("Can't delete this employee");

        $this->assertDatabaseHas('users', ['id' => $employee->id]);
    }

    public function test_user_delete_action_on_the_edit_page_is_also_blocked(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->create();
        Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);

        $this->actingAs($admin);

        Livewire::test(EditUser::class, ['record' => $employee->getRouteKey()])
            ->callAction('delete')
            ->assertActionHalted('delete')
            ->assertNotified("Can't delete this employee");

        $this->assertDatabaseHas('users', ['id' => $employee->id]);
    }

    public function test_bulk_deleting_users_is_blocked_entirely_if_any_selected_user_has_related_records(): void
    {
        $admin = User::factory()->admin()->create();
        $emptyUser = User::factory()->create();
        $busyUser = User::factory()->create();
        Prospect::factory()->create(['assigned_to' => $busyUser->id, 'created_by' => $busyUser->id]);

        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->callTableBulkAction('delete', [$emptyUser, $busyUser])
            ->assertNotified("Can't delete 1 of the selected employees");

        // Neither was deleted — a partial bulk delete would be more
        // confusing than blocking the whole batch and naming what's stuck.
        $this->assertDatabaseHas('users', ['id' => $emptyUser->id]);
        $this->assertDatabaseHas('users', ['id' => $busyUser->id]);
    }

    // --- Section 8: Call Record deletion ---

    public function test_admin_can_delete_a_call_record_once_its_downstream_record_is_gone(): void
    {
        $admin = User::factory()->admin()->create();
        $prospect = Prospect::factory()->create();
        $call = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $prospect->assigned_to,
            'called_at' => now(),
            'outcome' => CallOutcome::NoAnswer,
        ]);
        $call->fresh()->followUp->delete();

        $this->actingAs($admin);

        Livewire::test(ListCallRecords::class)
            ->callTableAction('delete', $call)
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseMissing('call_records', ['id' => $call->id]);
    }

    public function test_deleting_a_call_record_that_already_routed_is_blocked(): void
    {
        $admin = User::factory()->admin()->create();
        $prospect = Prospect::factory()->create();
        $call = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $prospect->assigned_to,
            'called_at' => now(),
            'outcome' => CallOutcome::NoAnswer,
        ]);

        $this->actingAs($admin);

        Livewire::test(ListCallRecords::class)
            ->callTableAction('delete', $call)
            ->assertTableActionHalted('delete')
            ->assertNotified("Can't delete this call record");

        $this->assertDatabaseHas('call_records', ['id' => $call->id]);
    }

    // --- Section 9 (folded into the same fix): Lead deletion when it has a Proposal ---

    public function test_admin_can_delete_a_lead_with_no_proposal(): void
    {
        $admin = User::factory()->admin()->create();
        $prospect = Prospect::factory()->create();
        $lead = Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $prospect->assigned_to,
            'created_by' => $prospect->created_by,
            'stage' => LeadStage::RequirementCollection,
            'temperature' => 'warm',
        ]);

        $this->actingAs($admin);

        Livewire::test(ListLeads::class)
            ->callTableAction('delete', $lead)
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseMissing('leads', ['id' => $lead->id]);
    }

    public function test_deleting_a_lead_with_a_proposal_is_blocked(): void
    {
        $admin = User::factory()->admin()->create();
        $prospect = Prospect::factory()->create();
        $lead = Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $prospect->assigned_to,
            'created_by' => $prospect->created_by,
            'stage' => LeadStage::Validated,
            'temperature' => 'hot',
        ]);
        Proposal::create([
            'lead_id' => $lead->id,
            'prospect_id' => $prospect->id,
            'assigned_to' => $prospect->assigned_to,
            'created_by' => $prospect->created_by,
            'stage' => ProposalStage::BeingPrepared,
        ]);

        $this->actingAs($admin);

        Livewire::test(ListLeads::class)
            ->callTableAction('delete', $lead)
            ->assertTableActionHalted('delete')
            ->assertNotified("Can't delete this lead");

        $this->assertDatabaseHas('leads', ['id' => $lead->id]);
    }

    public function test_lead_delete_action_on_the_edit_page_is_also_blocked_by_its_proposal(): void
    {
        $admin = User::factory()->admin()->create();
        $prospect = Prospect::factory()->create();
        $lead = Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $prospect->assigned_to,
            'created_by' => $prospect->created_by,
            'stage' => LeadStage::Validated,
            'temperature' => 'hot',
        ]);
        Proposal::create([
            'lead_id' => $lead->id,
            'prospect_id' => $prospect->id,
            'assigned_to' => $prospect->assigned_to,
            'created_by' => $prospect->created_by,
            'stage' => ProposalStage::BeingPrepared,
        ]);

        $this->actingAs($admin);

        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->callAction('delete')
            ->assertActionHalted('delete')
            ->assertNotified("Can't delete this lead");

        $this->assertDatabaseHas('leads', ['id' => $lead->id]);
    }
}
