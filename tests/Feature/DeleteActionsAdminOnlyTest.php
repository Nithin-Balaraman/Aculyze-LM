<?php

namespace Tests\Feature;

use App\Enums\AppointmentStage;
use App\Enums\CallOutcome;
use App\Enums\LeadStage;
use App\Enums\ProposalStage;
use App\Filament\Resources\AppointmentResource\Pages\ListAppointments;
use App\Filament\Resources\CallRecordResource\Pages\ListCallRecords;
use App\Filament\Resources\LeadResource\Pages\ListLeads;
use App\Filament\Resources\ProposalResource\Pages\ListProposals;
use App\Filament\Resources\ProspectResource\Pages\ListProspects;
use App\Models\Appointment;
use App\Models\CallRecord;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Neither Filament's built-in DeleteAction nor DeleteBulkAction check any
 * policy on their own — deleting is only actually blocked if the resource
 * explicitly hides the action. Row-level delete and the "Bulk actions" ->
 * "Delete selected" checkbox flow both used to be visible (and functional)
 * for regular Employees on CallRecordResource, ProspectResource,
 * LeadResource, ProposalResource, and AppointmentResource — mirrors the
 * fix already in place on FollowUpResource (see
 * FollowUpCompletedTest::test_delete_action_is_admin_only()).
 */
class DeleteActionsAdminOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_call_record_delete_actions_are_admin_only(): void
    {
        $employee = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $prospect = Prospect::factory()->create();
        $call = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $prospect->assigned_to,
            'called_at' => now(),
            'outcome' => CallOutcome::NoAnswer,
        ]);

        $this->actingAs($employee);
        Livewire::test(ListCallRecords::class)
            ->assertTableActionHidden('delete', $call)
            ->assertTableBulkActionHidden('delete');

        $this->actingAs($admin);
        Livewire::test(ListCallRecords::class)
            ->assertTableActionVisible('delete', $call)
            ->assertTableBulkActionVisible('delete');
    }

    public function test_prospect_delete_actions_are_admin_only(): void
    {
        $employee = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);

        $this->actingAs($employee);
        Livewire::test(ListProspects::class)
            ->assertTableActionHidden('delete', $prospect)
            ->assertTableBulkActionHidden('delete');

        $this->actingAs($admin);
        Livewire::test(ListProspects::class)
            ->assertTableActionVisible('delete', $prospect)
            ->assertTableBulkActionVisible('delete');
    }

    public function test_lead_delete_actions_are_admin_only(): void
    {
        $employee = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $prospect = Prospect::factory()->create();
        $lead = Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $prospect->assigned_to,
            'created_by' => $prospect->created_by,
            'stage' => LeadStage::RequirementCollection,
            'temperature' => 'warm',
        ]);

        $this->actingAs($employee);
        Livewire::test(ListLeads::class)
            ->assertTableActionHidden('delete', $lead)
            ->assertTableBulkActionHidden('delete');

        $this->actingAs($admin);
        Livewire::test(ListLeads::class)
            ->assertTableActionVisible('delete', $lead)
            ->assertTableBulkActionVisible('delete');
    }

    public function test_proposal_delete_actions_are_admin_only(): void
    {
        $employee = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $prospect = Prospect::factory()->create();
        $lead = Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $prospect->assigned_to,
            'created_by' => $prospect->created_by,
            'stage' => LeadStage::Validated,
            'temperature' => 'hot',
            'notes' => 'Validated in test fixture.',
        ]);
        $proposal = Proposal::create([
            'lead_id' => $lead->id,
            'prospect_id' => $prospect->id,
            'assigned_to' => $prospect->assigned_to,
            'created_by' => $prospect->created_by,
            'stage' => ProposalStage::BeingPrepared,
        ]);

        $this->actingAs($employee);
        Livewire::test(ListProposals::class)
            ->assertTableActionHidden('delete', $proposal)
            ->assertTableBulkActionHidden('delete');

        $this->actingAs($admin);
        Livewire::test(ListProposals::class)
            ->assertTableActionVisible('delete', $proposal)
            ->assertTableBulkActionVisible('delete');
    }

    public function test_appointment_delete_actions_are_admin_only(): void
    {
        $employee = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $prospect = Prospect::factory()->create();
        $appointment = Appointment::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $prospect->assigned_to,
            'created_by' => $prospect->created_by,
            'appointment_at' => now(),
            'stage' => AppointmentStage::VisitConducted,
        ]);

        $this->actingAs($employee);
        Livewire::test(ListAppointments::class)
            ->assertTableActionHidden('delete', $appointment)
            ->assertTableBulkActionHidden('delete');

        $this->actingAs($admin);
        Livewire::test(ListAppointments::class)
            ->assertTableActionVisible('delete', $appointment)
            ->assertTableBulkActionVisible('delete');
    }
}
