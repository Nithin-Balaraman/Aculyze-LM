<?php

namespace Tests\Feature;

use App\Enums\LeadStatus;
use App\Enums\ProposalContinuation;
use App\Enums\ProposalOutcome;
use App\Enums\ProposalStage;
use App\Models\Demo;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\Prospect;
use App\Models\User;
use App\Services\WorkflowTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Phase 3: WorkflowTransitionService::transitionLeadStatus() (standalone
 * Lead status change) and transitionProposalContinuation() (the approved,
 * narrow Proposal -> Follow-Up/Demo/Requirement-Clarification set).
 */
class WorkflowTransitionServiceLeadProposalTest extends TestCase
{
    use RefreshDatabase;

    private function newLead(User $user): Lead
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);

        return Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $user->id,
            'created_by' => $user->id,
            'stage' => 'requirement_collection',
            'status' => LeadStatus::RequirementCollection,
            'temperature' => 'warm',
        ]);
    }

    private function newProposal(User $user, ?ProposalOutcome $outcome = null): Proposal
    {
        $lead = $this->newLead($user);
        $lead->update(['status' => LeadStatus::ProposalRequired, 'notes' => 'Ready for Proposal.']);

        return Proposal::create([
            'lead_id' => $lead->id,
            'prospect_id' => $lead->prospect_id,
            'assigned_to' => $user->id,
            'created_by' => $user->id,
            'stage' => ProposalStage::BeingPrepared,
            'outcome' => $outcome,
            'notes' => $outcome !== null ? 'Outcome notes.' : null,
        ]);
    }

    public function test_transition_lead_status_changes_status_and_leaves_stage_untouched(): void
    {
        $user = User::factory()->create();
        $lead = $this->newLead($user);

        app(WorkflowTransitionService::class)->transitionLeadStatus($lead, LeadStatus::FollowUpRequired);

        $lead->refresh();
        $this->assertSame(LeadStatus::FollowUpRequired, $lead->status);
        $this->assertSame('requirement_collection', $lead->stage->value);
    }

    public function test_proposal_to_follow_up_preserves_proposal_and_creates_exactly_one_follow_up(): void
    {
        $user = User::factory()->create();
        $proposal = $this->newProposal($user);

        $followUp = app(WorkflowTransitionService::class)->transitionProposalContinuation(
            $proposal, ProposalContinuation::FollowUpRequired, ['follow_up_at' => now()->addDays(3), 'reason' => 'Check back next week']
        );

        $this->assertInstanceOf(FollowUp::class, $followUp);
        $this->assertSame('proposal', $followUp->origin_type);
        $this->assertSame($proposal->id, $followUp->origin_id);
        $this->assertSame(1, FollowUp::count());
        $this->assertSame(0, Demo::count());
        $this->assertSame(1, Lead::count());
        $this->assertDatabaseHas('proposals', ['id' => $proposal->id]);
        $this->assertNull($proposal->fresh()->outcome);
    }

    public function test_proposal_to_demo_preserves_proposal_and_creates_exactly_one_demo_for_the_same_lead(): void
    {
        $user = User::factory()->create();
        $proposal = $this->newProposal($user);

        $demo = app(WorkflowTransitionService::class)->transitionProposalContinuation(
            $proposal, ProposalContinuation::DemoRequired, [
                'demo_at' => now()->addDays(5),
                'mode' => 'online',
                'meeting_link' => 'https://meet.example.com/x',
            ]
        );

        $this->assertInstanceOf(Demo::class, $demo);
        $this->assertSame($proposal->lead_id, $demo->lead_id);
        $this->assertSame('proposal', $demo->origin_type);
        $this->assertSame($proposal->id, $demo->origin_id);
        $this->assertSame(1, Demo::count());
        $this->assertSame(0, FollowUp::count());
        $this->assertSame(1, Lead::count());
        $this->assertDatabaseHas('proposals', ['id' => $proposal->id]);
        $this->assertNull($proposal->fresh()->outcome);
    }

    public function test_proposal_to_requirement_clarification_reuses_the_same_lead(): void
    {
        $user = User::factory()->create();
        $proposal = $this->newProposal($user);
        $originalLeadId = $proposal->lead_id;

        $lead = app(WorkflowTransitionService::class)->transitionProposalContinuation(
            $proposal, ProposalContinuation::RequirementClarificationRequired, ['clarification_notes' => 'Need to re-confirm scope.']
        );

        $this->assertInstanceOf(Lead::class, $lead);
        $this->assertSame($originalLeadId, $lead->id);
        $this->assertSame(1, Lead::count());
        $this->assertSame(LeadStatus::RequirementCollection, $lead->fresh()->status);
        $this->assertDatabaseHas('proposals', ['id' => $proposal->id]);
        $this->assertNull($proposal->fresh()->outcome);
    }

    public function test_won_proposal_rejects_every_ordinary_continuation(): void
    {
        $user = User::factory()->create();
        $proposal = $this->newProposal($user, ProposalOutcome::Won);

        foreach (ProposalContinuation::cases() as $continuation) {
            try {
                app(WorkflowTransitionService::class)->transitionProposalContinuation($proposal, $continuation, [
                    'follow_up_at' => now()->addDay(), 'reason' => 'x',
                    'demo_at' => now()->addDay(), 'mode' => 'online', 'meeting_link' => 'https://x.example.com',
                    'clarification_notes' => 'x',
                ]);
                $this->fail("Expected {$continuation->value} to be rejected for a Won Proposal.");
            } catch (LogicException $e) {
                $this->assertStringContainsString('Won', $e->getMessage());
            }
        }
    }

    public function test_lost_proposal_rejects_every_ordinary_continuation(): void
    {
        $user = User::factory()->create();
        $proposal = $this->newProposal($user, ProposalOutcome::Lost);

        foreach (ProposalContinuation::cases() as $continuation) {
            try {
                app(WorkflowTransitionService::class)->transitionProposalContinuation($proposal, $continuation, [
                    'follow_up_at' => now()->addDay(), 'reason' => 'x',
                    'demo_at' => now()->addDay(), 'mode' => 'online', 'meeting_link' => 'https://x.example.com',
                    'clarification_notes' => 'x',
                ]);
                $this->fail("Expected {$continuation->value} to be rejected for a Lost Proposal.");
            } catch (LogicException $e) {
                $this->assertStringContainsString('Lost', $e->getMessage());
            }
        }
    }

    public function test_hold_proposal_allows_follow_up_only(): void
    {
        $user = User::factory()->create();
        $proposal = $this->newProposal($user, ProposalOutcome::Hold);

        $followUp = app(WorkflowTransitionService::class)->transitionProposalContinuation(
            $proposal, ProposalContinuation::FollowUpRequired, ['follow_up_at' => now()->addDay(), 'reason' => 'Check back']
        );
        $this->assertInstanceOf(FollowUp::class, $followUp);

        foreach ([ProposalContinuation::DemoRequired, ProposalContinuation::RequirementClarificationRequired] as $continuation) {
            try {
                app(WorkflowTransitionService::class)->transitionProposalContinuation($proposal, $continuation, [
                    'demo_at' => now()->addDay(), 'mode' => 'online', 'meeting_link' => 'https://x.example.com',
                    'clarification_notes' => 'x',
                ]);
                $this->fail("Expected {$continuation->value} to be rejected for a Hold Proposal.");
            } catch (LogicException $e) {
                $this->assertStringContainsString('Hold', $e->getMessage());
            }
        }
    }
}
