<?php

namespace Tests\Feature;

use App\Enums\LeadStage;
use App\Enums\ProposalOutcome;
use App\Enums\ProposalStage;
use App\Filament\Resources\ProposalResource\Pages\CreateProposal;
use App\Filament\Resources\ProposalResource\Pages\EditProposal;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A Proposal may only be saved with a final outcome (Won or Lost) when Notes
 * is genuinely present — mirrors CallRecordOthersNotesTest (Notes required
 * for outcomes where something needs documenting). Hold and "still in
 * progress" (null) are unaffected.
 */
class ProposalOutcomeNotesTest extends TestCase
{
    use RefreshDatabase;

    private function validatedLead(User $owner): Lead
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id]);

        return Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $owner->id,
            'created_by' => $owner->id,
            'stage' => LeadStage::Validated,
            'temperature' => 'hot',
            'notes' => 'Validated in test fixture.',
        ]);
    }

    private function proposal(User $owner, ?ProposalOutcome $outcome, ?string $notes = null): Proposal
    {
        $lead = $this->validatedLead($owner);

        return Proposal::create([
            'lead_id' => $lead->id,
            'prospect_id' => $lead->prospect_id,
            'assigned_to' => $owner->id,
            'created_by' => $owner->id,
            'stage' => ProposalStage::BeingPrepared,
            'outcome' => $outcome,
            'notes' => $notes,
        ]);
    }

    public function test_creating_a_proposal_with_no_outcome_does_not_require_notes(): void
    {
        $employee = User::factory()->create();
        $lead = $this->validatedLead($employee);
        $this->actingAs($employee);

        Livewire::test(CreateProposal::class)
            ->fillForm([
                'lead_id' => $lead->id,
                'stage' => ProposalStage::BeingPrepared->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('proposals', 1);
    }

    public function test_creating_a_proposal_with_outcome_hold_does_not_require_notes(): void
    {
        $employee = User::factory()->create();
        $lead = $this->validatedLead($employee);
        $this->actingAs($employee);

        Livewire::test(CreateProposal::class)
            ->fillForm([
                'lead_id' => $lead->id,
                'stage' => ProposalStage::BeingPrepared->value,
                'outcome' => ProposalOutcome::Hold->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('proposals', 1);
    }

    public function test_creating_a_won_proposal_without_notes_fails_validation(): void
    {
        $employee = User::factory()->create();
        $lead = $this->validatedLead($employee);
        $this->actingAs($employee);

        Livewire::test(CreateProposal::class)
            ->fillForm([
                'lead_id' => $lead->id,
                'stage' => ProposalStage::BeingPrepared->value,
                'outcome' => ProposalOutcome::Won->value,
                'notes' => null,
            ])
            ->call('create')
            ->assertHasFormErrors(['notes']);

        $this->assertDatabaseCount('proposals', 0);
    }

    public function test_creating_a_lost_proposal_without_notes_fails_validation(): void
    {
        $employee = User::factory()->create();
        $lead = $this->validatedLead($employee);
        $this->actingAs($employee);

        Livewire::test(CreateProposal::class)
            ->fillForm([
                'lead_id' => $lead->id,
                'stage' => ProposalStage::BeingPrepared->value,
                'outcome' => ProposalOutcome::Lost->value,
                'notes' => null,
            ])
            ->call('create')
            ->assertHasFormErrors(['notes']);

        $this->assertDatabaseCount('proposals', 0);
    }

    /**
     * Regression guard: fillForm() seeds raw scalars, but a real Select
     * interaction rehydrates $get('outcome') as the actual ProposalOutcome
     * enum case, not its string value — mirrors the same guard in
     * CallRecordOthersNotesTest.
     */
    public function test_creating_a_won_proposal_without_notes_fails_validation_when_outcome_is_set_as_the_hydrated_enum_case(): void
    {
        $employee = User::factory()->create();
        $lead = $this->validatedLead($employee);
        $this->actingAs($employee);

        Livewire::test(CreateProposal::class)
            ->fillForm([
                'lead_id' => $lead->id,
                'stage' => ProposalStage::BeingPrepared->value,
                'notes' => null,
            ])
            ->set('data.outcome', ProposalOutcome::Won)
            ->call('create')
            ->assertHasFormErrors(['notes']);

        $this->assertDatabaseCount('proposals', 0);
    }

    public function test_creating_a_won_proposal_with_whitespace_only_notes_fails_validation(): void
    {
        $employee = User::factory()->create();
        $lead = $this->validatedLead($employee);
        $this->actingAs($employee);

        Livewire::test(CreateProposal::class)
            ->fillForm([
                'lead_id' => $lead->id,
                'stage' => ProposalStage::BeingPrepared->value,
                'outcome' => ProposalOutcome::Won->value,
                'notes' => "   \n\t  ",
            ])
            ->call('create')
            ->assertHasFormErrors(['notes']);

        $this->assertDatabaseCount('proposals', 0);
    }

    public function test_creating_a_won_proposal_with_valid_notes_succeeds(): void
    {
        $employee = User::factory()->create();
        $lead = $this->validatedLead($employee);
        $this->actingAs($employee);

        Livewire::test(CreateProposal::class)
            ->fillForm([
                'lead_id' => $lead->id,
                'stage' => ProposalStage::BeingPrepared->value,
                'outcome' => ProposalOutcome::Won->value,
                'notes' => 'Client signed the contract today.',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $proposal = Proposal::sole();
        $this->assertSame(ProposalOutcome::Won, $proposal->outcome);
        $this->assertSame('Client signed the contract today.', $proposal->notes);
    }

    public function test_editing_a_proposal_into_lost_without_notes_fails_validation(): void
    {
        $employee = User::factory()->create();
        $proposal = $this->proposal($employee, null);
        $this->actingAs($employee);

        Livewire::test(EditProposal::class, ['record' => $proposal->getRouteKey()])
            ->fillForm(['outcome' => ProposalOutcome::Lost->value, 'notes' => null])
            ->call('save')
            ->assertHasFormErrors(['notes']);

        $this->assertNull($proposal->fresh()->outcome);
    }

    public function test_editing_a_proposal_into_lost_with_notes_succeeds(): void
    {
        $employee = User::factory()->create();
        $proposal = $this->proposal($employee, null);
        $this->actingAs($employee);

        Livewire::test(EditProposal::class, ['record' => $proposal->getRouteKey()])
            ->fillForm(['outcome' => ProposalOutcome::Lost->value, 'notes' => 'Went with a cheaper competitor.'])
            ->call('save')
            ->assertHasNoFormErrors();

        $proposal->refresh();
        $this->assertSame(ProposalOutcome::Lost, $proposal->outcome);
        $this->assertSame('Went with a cheaper competitor.', $proposal->notes);
    }

    /**
     * Defense in depth: the model guard must reject a Won/Lost save even
     * when it bypasses the Filament form entirely.
     */
    public function test_model_guard_rejects_a_won_proposal_without_notes(): void
    {
        $this->expectException(\LogicException::class);

        $admin = User::factory()->admin()->create();
        $this->proposal($admin, ProposalOutcome::Won, null);
    }

    public function test_model_guard_rejects_a_lost_proposal_without_notes(): void
    {
        $this->expectException(\LogicException::class);

        $admin = User::factory()->admin()->create();
        $this->proposal($admin, ProposalOutcome::Lost, null);
    }

    public function test_model_guard_allows_a_hold_proposal_without_notes(): void
    {
        $admin = User::factory()->admin()->create();
        $proposal = $this->proposal($admin, ProposalOutcome::Hold, null);

        $this->assertSame(ProposalOutcome::Hold, $proposal->fresh()->outcome);
    }
}
