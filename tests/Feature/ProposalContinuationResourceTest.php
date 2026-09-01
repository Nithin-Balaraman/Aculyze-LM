<?php

namespace Tests\Feature;

use App\Enums\LeadStatus;
use App\Enums\ProposalContinuation;
use App\Enums\ProposalOutcome;
use App\Enums\ProposalStage;
use App\Filament\Resources\ProposalResource\Pages\ListProposals;
use App\Models\Demo;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 3: the "Continue" row action on Proposals, wired to
 * WorkflowTransitionService::transitionProposalContinuation(). The service
 * itself is already fully covered by WorkflowTransitionServiceLeadProposalTest
 * — this proves the UI wiring (visibility per outcome, form fields).
 */
class ProposalContinuationResourceTest extends TestCase
{
    use RefreshDatabase;

    private function makeProposal(User $user, ?ProposalOutcome $outcome = null): Proposal
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
        $lead = Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $user->id,
            'created_by' => $user->id,
            'stage' => 'requirement_collection',
            'status' => LeadStatus::ProposalRequired,
            'temperature' => 'warm',
            'notes' => 'Ready for Proposal.',
        ]);

        return Proposal::create([
            'lead_id' => $lead->id,
            'prospect_id' => $prospect->id,
            'assigned_to' => $user->id,
            'created_by' => $user->id,
            'stage' => ProposalStage::BeingPrepared,
            'outcome' => $outcome,
            'notes' => $outcome !== null ? 'Outcome notes.' : null,
        ]);
    }

    public function test_continue_action_is_hidden_for_won_and_lost_proposals(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $won = $this->makeProposal($user, ProposalOutcome::Won);
        $lost = $this->makeProposal($user, ProposalOutcome::Lost);

        Livewire::test(ListProposals::class)
            ->assertTableActionHidden('continueProposal', $won)
            ->assertTableActionHidden('continueProposal', $lost);
    }

    public function test_continue_action_via_the_ui_creates_a_follow_up_and_preserves_the_proposal(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $proposal = $this->makeProposal($user);

        Livewire::test(ListProposals::class)
            ->callTableAction('continueProposal', $proposal, data: [
                'continuation' => ProposalContinuation::FollowUpRequired->value,
                'follow_up_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
                'reason' => 'Check back next week.',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(1, FollowUp::count());
        $this->assertDatabaseHas('proposals', ['id' => $proposal->id]);
        $this->assertNull($proposal->fresh()->outcome);
    }

    public function test_continue_action_via_the_ui_creates_a_demo(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $proposal = $this->makeProposal($user);

        Livewire::test(ListProposals::class)
            ->callTableAction('continueProposal', $proposal, data: [
                'continuation' => ProposalContinuation::DemoRequired->value,
                'demo_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
                'mode' => 'online',
                'meeting_link' => 'https://meet.example.com/demo',
            ])
            ->assertHasNoTableActionErrors();

        $demo = Demo::sole();
        $this->assertSame($proposal->lead_id, $demo->lead_id);
        $this->assertSame('proposal', $demo->origin_type);
    }

    public function test_hold_proposal_only_offers_follow_up_required_and_rejects_demo_required_server_side(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $proposal = $this->makeProposal($user, ProposalOutcome::Hold);

        Livewire::test(ListProposals::class)
            ->assertTableActionVisible('continueProposal', $proposal)
            ->callTableAction('continueProposal', $proposal, data: [
                'continuation' => ProposalContinuation::FollowUpRequired->value,
                'follow_up_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
                'reason' => 'Check back.',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(1, FollowUp::count());
    }
}
