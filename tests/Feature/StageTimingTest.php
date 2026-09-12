<?php

namespace Tests\Feature;

use App\Enums\AppointmentStage;
use App\Enums\LeadStage;
use App\Enums\ProposalStage;
use App\Models\Appointment;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\ProposalVersion;
use App\Models\Prospect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AGENTS.md sections 19, 22, 27, 45: stage_changed_at must move only when
 * the stage itself changes, never on unrelated edits.
 *
 * Phase 4A-3.1 correction (locked Decision 18): a Proposal's `outcome`
 * changing BY ITSELF no longer resets stage_changed_at — only `stage`
 * does. This narrows the Proposal-specific half of the rule above (Leads/
 * Appointments are untouched); see Proposal::booted()'s own updated
 * docblock for the full reasoning and the verification that no existing
 * runtime writer of `outcome` relied on the old behavior (every real write
 * path — PipelineBoard's dropProposal()/resolveCrossDropSource() — always
 * sets `stage` in the same write whenever it sets `outcome`).
 */
class StageTimingTest extends TestCase
{
    use RefreshDatabase;

    public function test_editing_lead_notes_does_not_reset_stage_changed_at(): void
    {
        $prospect = Prospect::factory()->create();
        $lead = Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $prospect->assigned_to,
            'created_by' => $prospect->created_by,
            'stage' => LeadStage::RequirementCollection,
            'temperature' => 'warm',
        ]);

        $originalStageChangedAt = $lead->stage_changed_at;
        $this->travel(5)->days();

        $lead->update(['notes' => 'Just a clarification, no stage movement.']);

        $this->assertTrue($lead->fresh()->stage_changed_at->equalTo($originalStageChangedAt));
    }

    public function test_changing_lead_stage_resets_stage_changed_at(): void
    {
        $prospect = Prospect::factory()->create();
        $lead = Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $prospect->assigned_to,
            'created_by' => $prospect->created_by,
            'stage' => LeadStage::RequirementCollection,
            'temperature' => 'warm',
        ]);

        $originalStageChangedAt = $lead->stage_changed_at;
        $this->travel(5)->days();

        $lead->update(['stage' => LeadStage::DemoScheduledOrDone]);

        $this->assertTrue($lead->fresh()->stage_changed_at->greaterThan($originalStageChangedAt));
    }

    public function test_editing_appointment_notes_does_not_reset_stage_changed_at(): void
    {
        $prospect = Prospect::factory()->create();
        $appointment = Appointment::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $prospect->assigned_to,
            'created_by' => $prospect->created_by,
            'appointment_at' => now(),
            'stage' => AppointmentStage::AppointmentMade,
        ]);

        $original = $appointment->stage_changed_at;
        $this->travel(3)->days();

        $appointment->update(['meeting_notes' => 'Rescheduled venue only.']);

        $this->assertTrue($appointment->fresh()->stage_changed_at->equalTo($original));
    }

    public function test_changing_appointment_stage_resets_stage_changed_at(): void
    {
        $prospect = Prospect::factory()->create();
        $appointment = Appointment::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $prospect->assigned_to,
            'created_by' => $prospect->created_by,
            'appointment_at' => now(),
            'stage' => AppointmentStage::AppointmentMade,
        ]);

        $original = $appointment->stage_changed_at;
        $this->travel(3)->days();

        $appointment->update(['stage' => AppointmentStage::VisitConducted]);

        $this->assertTrue($appointment->fresh()->stage_changed_at->greaterThan($original));
    }

    public function test_editing_proposal_notes_does_not_reset_stage_changed_at(): void
    {
        $proposal = $this->makeProposal();
        $original = $proposal->stage_changed_at;
        $this->travel(4)->days();

        $proposal->update(['notes' => 'Client asked a clarifying question.']);

        $this->assertTrue($proposal->fresh()->stage_changed_at->equalTo($original));
    }

    /**
     * Phase 4A-3.1 correction (locked Decision 18): outcome changing BY
     * ITSELF — with no accompanying stage change — must NOT reset
     * stage_changed_at. Renamed and re-asserted from this test's own prior
     * (pre-4A-3) expectation, which encoded the opposite, now-superseded
     * rule.
     */
    public function test_changing_proposal_outcome_alone_does_not_reset_stage_changed_at(): void
    {
        $proposal = $this->makeProposal();
        $original = $proposal->stage_changed_at;
        $this->travel(4)->days();

        // Phase 4A-3.5 cutover: Won now requires a valid winning_version_id
        // (DB CHECK) — forceFill since winning_version_id isn't fillable.
        $version = ProposalVersion::factory()->create(['proposal_id' => $proposal->id]);
        $proposal->forceFill(['outcome' => 'won', 'winning_version_id' => $version->id, 'notes' => 'Client signed.'])->save();

        $this->assertTrue($proposal->fresh()->stage_changed_at->equalTo($original));
    }

    /** Positive control: a genuine stage change still resets stage_changed_at exactly as before. */
    public function test_changing_proposal_stage_resets_stage_changed_at(): void
    {
        $proposal = $this->makeProposal();
        $original = $proposal->stage_changed_at;
        $this->travel(4)->days();

        $version = ProposalVersion::factory()->create(['proposal_id' => $proposal->id]);
        $proposal->forceFill(['stage' => ProposalStage::CustomerAccepted, 'outcome' => 'won', 'winning_version_id' => $version->id, 'notes' => 'Client signed.'])->save();

        $this->assertTrue($proposal->fresh()->stage_changed_at->greaterThan($original));
    }

    private function makeProposal(): Proposal
    {
        $prospect = Prospect::factory()->create();
        $lead = Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $prospect->assigned_to,
            'created_by' => $prospect->created_by,
            'stage' => LeadStage::Validated,
            'temperature' => 'hot',
            'notes' => 'Validated in test fixture.',
        ]);

        return Proposal::create([
            'lead_id' => $lead->id,
            'prospect_id' => $prospect->id,
            'assigned_to' => $prospect->assigned_to,
            'created_by' => $prospect->created_by,
            'stage' => ProposalStage::Sent,
        ]);
    }
}
