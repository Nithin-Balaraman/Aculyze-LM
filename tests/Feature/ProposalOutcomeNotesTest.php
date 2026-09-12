<?php

namespace Tests\Feature;

use App\Enums\LeadStage;
use App\Enums\ProposalOutcome;
use App\Enums\ProposalStage;
use App\Filament\Resources\ProposalResource\Pages\CreateProposal;
use App\Filament\Resources\ProposalResource\Pages\EditProposal;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\ProposalVersion;
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
 *
 * Phase 4A-3.5 cutover: Proposal Outcome (and Stage) are no longer editable
 * from this generic form at all — they are exclusively set by
 * ProposalSendService/ProposalClientResponseService — so the form-level
 * "outcome drives notes required" tests that used to fill an `outcome`
 * Select have been replaced by tests proving that field no longer exists,
 * plus a record-driven notes-required echo for an ALREADY-terminal Proposal.
 * The model's own saving() guard (tested directly here too) remains the
 * real enforcement point, exercised for real by ProposalClientResponseService.
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

        // Phase 4A-3.5 cutover: Won now requires a valid winning_version_id
        // (DB CHECK). A blank-notes Won/Lost attempt must still fail via
        // the model's OWN saving() guard on this very first create() call
        // (before any DB write) — untouched by the CHECK. A legitimately
        // notes-having Won fixture needs a real winner attached in a
        // second write, since the guard would otherwise pass but the CHECK
        // would reject the plain single-step insert.
        if ($outcome === ProposalOutcome::Won && filled($notes)) {
            $proposal = Proposal::create([
                'lead_id' => $lead->id,
                'prospect_id' => $lead->prospect_id,
                'assigned_to' => $owner->id,
                'created_by' => $owner->id,
                'stage' => ProposalStage::BeingPrepared,
                'notes' => $notes,
            ]);
            $version = ProposalVersion::factory()->create(['proposal_id' => $proposal->id]);
            $proposal->forceFill(['outcome' => ProposalOutcome::Won, 'winning_version_id' => $version->id])->save();

            return $proposal->fresh();
        }

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

    public function test_creating_a_proposal_does_not_require_notes(): void
    {
        $employee = User::factory()->create();
        $lead = $this->validatedLead($employee);
        $this->actingAs($employee);

        Livewire::test(CreateProposal::class)
            ->fillForm([
                'lead_id' => $lead->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('proposals', 1);
        $this->assertNull(Proposal::sole()->outcome);
    }

    /**
     * Phase 4A-3.5 cutover: the generic Create/Edit form no longer exposes
     * an editable Stage or Outcome Select, or a manual Value TextInput —
     * only ProposalSendService/ProposalClientResponseService may write
     * these fields now. Mirrors the equivalent assertions already
     * established for current_version_id/winning_version_id in
     * ManageCommercialVersionHistoryAndRegressionTest.
     */
    public function test_stage_outcome_and_value_are_no_longer_editable_selects_on_the_generic_form(): void
    {
        $contents = file_get_contents(app_path('Filament/Resources/ProposalResource.php'));

        $this->assertStringNotContainsString("Select::make('stage')", $contents);
        $this->assertStringNotContainsString("Select::make('outcome')", $contents);
        $this->assertStringNotContainsString("TextInput::make('value')", $contents);
        $this->assertStringContainsString("Placeholder::make('stage_display')", $contents);
        $this->assertStringContainsString("Placeholder::make('outcome_display')", $contents);
        $this->assertStringContainsString("Placeholder::make('value_display')", $contents);
    }

    public function test_creating_a_proposal_cannot_set_outcome_via_the_form(): void
    {
        $employee = User::factory()->create();
        $lead = $this->validatedLead($employee);
        $this->actingAs($employee);

        // No 'outcome' field exists to fill any more — this proves the
        // form genuinely ignores any attempt to smuggle one in via
        // fillForm(), rather than merely being absent from the visible UI.
        Livewire::test(CreateProposal::class)
            ->fillForm([
                'lead_id' => $lead->id,
                'outcome' => ProposalOutcome::Won->value,
                'notes' => 'Client signed the contract today.',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $proposal = Proposal::sole();
        $this->assertNull($proposal->outcome);
        $this->assertSame(ProposalStage::BeingPrepared, $proposal->stage);
    }

    public function test_editing_a_proposal_cannot_set_outcome_via_the_form(): void
    {
        $employee = User::factory()->create();
        $proposal = $this->proposal($employee, null);
        $this->actingAs($employee);

        Livewire::test(EditProposal::class, ['record' => $proposal->getRouteKey()])
            ->fillForm(['outcome' => ProposalOutcome::Lost->value, 'notes' => 'Went with a cheaper competitor.'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($proposal->fresh()->outcome);
    }

    /**
     * Friendly form-level echo of the model guard for a Proposal that is
     * ALREADY terminal (e.g. set by ProposalClientResponseService) —
     * outcome itself can't be changed from this form any more, but Notes
     * must still not be blankable out from under a decided Proposal.
     */
    public function test_editing_notes_blank_on_an_already_won_proposal_fails_validation(): void
    {
        $employee = User::factory()->create();
        $proposal = $this->proposal($employee, ProposalOutcome::Won, 'Signed already.');
        $this->actingAs($employee);

        Livewire::test(EditProposal::class, ['record' => $proposal->getRouteKey()])
            ->fillForm(['notes' => null])
            ->call('save')
            ->assertHasFormErrors(['notes']);

        $this->assertSame('Signed already.', $proposal->fresh()->notes);
    }

    public function test_editing_notes_on_an_already_lost_proposal_still_requires_notes(): void
    {
        $employee = User::factory()->create();
        $proposal = $this->proposal($employee, ProposalOutcome::Lost, 'Went with a competitor.');
        $this->actingAs($employee);

        Livewire::test(EditProposal::class, ['record' => $proposal->getRouteKey()])
            ->fillForm(['notes' => "   \n\t  "])
            ->call('save')
            ->assertHasFormErrors(['notes']);
    }

    public function test_editing_notes_on_a_hold_proposal_does_not_require_notes(): void
    {
        $employee = User::factory()->create();
        $proposal = $this->proposal($employee, ProposalOutcome::Hold, null);
        $this->actingAs($employee);

        Livewire::test(EditProposal::class, ['record' => $proposal->getRouteKey()])
            ->fillForm(['notes' => null])
            ->call('save')
            ->assertHasNoFormErrors();
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
