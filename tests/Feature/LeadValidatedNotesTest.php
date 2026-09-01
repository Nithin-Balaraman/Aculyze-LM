<?php

namespace Tests\Feature;

use App\Enums\LeadStage;
use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Enums\ProposalStage;
use App\Filament\Resources\LeadResource\Pages\CreateLead;
use App\Filament\Resources\LeadResource\Pages\EditLead;
use App\Filament\Resources\LeadResource\Pages\ListLeads;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Validated Lead Proposal Gate batch, Section 1: a Lead may only be saved
 * as Validated when Notes/Remarks is genuinely present, and the existing
 * "Create Proposal" row action (App\Filament\Resources\LeadResource) is
 * only available once a persisted Lead satisfies both conditions —
 * previously it only checked stage, is_lost, and the absence of an
 * existing Proposal, with no Notes requirement at all.
 */
class LeadValidatedNotesTest extends TestCase
{
    use RefreshDatabase;

    private function baseFormData(array $overrides = []): array
    {
        $prospect = Prospect::factory()->create();

        return array_merge([
            'prospect_id' => $prospect->id,
            'assigned_to' => $prospect->assigned_to,
            'stage' => LeadStage::RequirementCollection->value,
            'temperature' => LeadTemperature::Warm->value,
        ], $overrides);
    }

    private function makeLead(User $owner, LeadStage $stage, ?string $notes = null): Lead
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id]);

        return Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $owner->id,
            'created_by' => $owner->id,
            'stage' => $stage,
            'temperature' => LeadTemperature::Warm,
            'notes' => $notes,
        ]);
    }

    // --- Section 1.12 items 1-2: other stages keep existing optionality ---

    public function test_requirement_collection_lead_can_be_created_without_notes(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateLead::class)
            ->fillForm($this->baseFormData(['stage' => LeadStage::RequirementCollection->value, 'notes' => null]))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('leads', 1);
    }

    public function test_demo_scheduled_lead_can_be_created_without_notes(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateLead::class)
            ->fillForm($this->baseFormData(['stage' => LeadStage::DemoScheduledOrDone->value, 'notes' => null]))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('leads', 1);
    }

    // --- Section 1.12 items 3-6: creating a Validated Lead ---

    public function test_creating_a_validated_lead_without_notes_fails_validation(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateLead::class)
            ->fillForm($this->baseFormData(['stage' => LeadStage::Validated->value, 'notes' => null]))
            ->call('create')
            ->assertHasFormErrors(['notes']);

        $this->assertDatabaseCount('leads', 0);
    }

    /**
     * Regression guard: fillForm() seeds raw scalars, but a real Select
     * interaction rehydrates $get('stage') as the actual LeadStage enum
     * case, not its string value — a naive `$get('stage') ===
     * LeadStage::Validated->value` comparison silently never matches in
     * that path even though it matches fine under fillForm(), which is
     * exactly the gap that let the required-Notes rule not actually fire
     * in the browser. Setting the enum case directly here (rather than
     * ->value) reproduces that real hydrated type.
     */
    public function test_creating_a_validated_lead_without_notes_fails_validation_when_stage_is_set_as_the_hydrated_enum_case(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateLead::class)
            ->fillForm($this->baseFormData(['notes' => null]))
            ->set('data.stage', LeadStage::Validated)
            ->call('create')
            ->assertHasFormErrors(['notes']);

        $this->assertDatabaseCount('leads', 0);
    }

    public function test_creating_a_validated_lead_with_empty_notes_fails_validation(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateLead::class)
            ->fillForm($this->baseFormData(['stage' => LeadStage::Validated->value, 'notes' => '']))
            ->call('create')
            ->assertHasFormErrors(['notes']);

        $this->assertDatabaseCount('leads', 0);
    }

    public function test_creating_a_validated_lead_with_whitespace_only_notes_fails_validation(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateLead::class)
            ->fillForm($this->baseFormData(['stage' => LeadStage::Validated->value, 'notes' => "   \n\t  "]))
            ->call('create')
            ->assertHasFormErrors(['notes']);

        $this->assertDatabaseCount('leads', 0);
    }

    public function test_creating_a_validated_lead_with_valid_notes_succeeds(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateLead::class)
            ->fillForm($this->baseFormData(['stage' => LeadStage::Validated->value, 'notes' => 'Budget and timeline confirmed.']))
            ->call('create')
            ->assertHasNoFormErrors();

        $lead = Lead::sole();
        $this->assertSame(LeadStage::Validated, $lead->stage);
        $this->assertSame('Budget and timeline confirmed.', $lead->notes);
    }

    // --- Section 1.12 items 7-8: editing into Validated ---

    public function test_editing_a_lead_into_validated_without_notes_fails_validation(): void
    {
        $admin = User::factory()->admin()->create();
        $lead = $this->makeLead($admin, LeadStage::DemoScheduledOrDone, null);
        $this->actingAs($admin);

        Livewire::test(EditLead::class, ['record' => $lead->id])
            ->fillForm(['stage' => LeadStage::Validated->value, 'notes' => null])
            ->call('save')
            ->assertHasFormErrors(['notes']);

        $this->assertSame(LeadStage::DemoScheduledOrDone, $lead->fresh()->stage);
    }

    public function test_editing_a_lead_into_validated_with_notes_succeeds(): void
    {
        $admin = User::factory()->admin()->create();
        $lead = $this->makeLead($admin, LeadStage::DemoScheduledOrDone, null);
        $this->actingAs($admin);

        Livewire::test(EditLead::class, ['record' => $lead->id])
            ->fillForm(['stage' => LeadStage::Validated->value, 'notes' => 'Confirmed requirements with the client.'])
            ->call('save')
            ->assertHasNoFormErrors();

        $lead->refresh();
        $this->assertSame(LeadStage::Validated, $lead->stage);
        $this->assertSame('Confirmed requirements with the client.', $lead->notes);
    }

    // --- Section 1.12 items 9-11: Create Proposal visibility ---

    public function test_existing_validated_lead_with_blank_notes_does_not_expose_create_proposal(): void
    {
        $employee = User::factory()->create();
        // Bypasses the model guard deliberately (forceFill+saveQuietly) to
        // simulate historical invalid data, per Section 1.7 — the app must
        // never fabricate Notes for it, only hide the action.
        $lead = $this->makeLead($employee, LeadStage::DemoScheduledOrDone, 'temp');
        Lead::withoutEvents(fn () => $lead->forceFill(['stage' => LeadStage::Validated, 'notes' => null])->save());

        $this->actingAs($employee);

        Livewire::test(ListLeads::class)
            ->assertTableActionHidden('createProposal', $lead->fresh());
    }

    public function test_validated_lead_with_notes_exposes_create_proposal_for_an_authorized_user(): void
    {
        $employee = User::factory()->create();
        $lead = $this->makeLead($employee, LeadStage::Validated, 'Ready to move forward.');

        $this->actingAs($employee);

        Livewire::test(ListLeads::class)
            ->assertTableActionVisible('createProposal', $lead);
    }

    /**
     * Phase 3: LeadStatus::ProposalRequired (reached via the new
     * WorkflowTransitionService-driven workflow, legacy stage left frozen at
     * a non-Validated value) also exposes Create Proposal — the eligibility
     * check now recognizes normalized status alongside the legacy stage.
     */
    public function test_proposal_required_status_lead_with_frozen_non_validated_stage_exposes_create_proposal(): void
    {
        $employee = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);

        $lead = Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => LeadStage::RequirementCollection,
            'status' => LeadStatus::RequirementCollection,
            'temperature' => LeadTemperature::Warm,
        ]);

        $lead->update(['status' => LeadStatus::ProposalRequired, 'notes' => 'Requirement confirmed, ready for Proposal.']);
        $this->assertSame(LeadStage::RequirementCollection, $lead->fresh()->stage);

        $this->actingAs($employee);

        Livewire::test(ListLeads::class)
            ->assertTableActionVisible('createProposal', $lead);
    }

    public function test_non_validated_lead_with_notes_does_not_expose_create_proposal(): void
    {
        $employee = User::factory()->create();
        $lead = $this->makeLead($employee, LeadStage::DemoScheduledOrDone, 'Some notes here.');

        $this->actingAs($employee);

        Livewire::test(ListLeads::class)
            ->assertTableActionHidden('createProposal', $lead);
    }

    // --- Section 1.12 items 12-13: authorization/scoping ---

    public function test_employee_cannot_use_create_proposal_via_the_policy_on_another_employees_lead(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $lead = $this->makeLead($owner, LeadStage::Validated, 'Ready to go.');

        $this->assertFalse($intruder->can('update', $lead));
    }

    public function test_admin_can_use_create_proposal_on_an_eligible_lead(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->create();
        $lead = $this->makeLead($employee, LeadStage::Validated, 'Ready to go.');

        $this->actingAs($admin);

        Livewire::test(ListLeads::class)
            ->assertTableActionVisible('createProposal', $lead);
    }

    public function test_validated_lead_with_notes_but_already_having_a_proposal_does_not_expose_create_proposal_again(): void
    {
        $employee = User::factory()->create();
        $lead = $this->makeLead($employee, LeadStage::Validated, 'Ready to go.');
        Proposal::create([
            'lead_id' => $lead->id,
            'prospect_id' => $lead->prospect_id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => ProposalStage::BeingPrepared,
        ]);

        $this->actingAs($employee);

        Livewire::test(ListLeads::class)
            ->assertTableActionHidden('createProposal', $lead->fresh());
    }
}
