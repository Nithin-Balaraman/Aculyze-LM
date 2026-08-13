<?php

namespace Tests\Feature;

use App\Enums\CallOutcome;
use App\Enums\LeadStage;
use App\Enums\ProposalStage;
use App\Models\Appointment;
use App\Models\CallRecord;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\Prospect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Change Request Section 7: Prospect uses SoftDeletes, but none of the five
 * `prospect()` relationships (CallRecord, FollowUp, Appointment, Lead,
 * Proposal) used to account for that — soft-deleting a Prospect made every
 * one of them return null for ->prospect, blanking the company name
 * everywhere in that record's history. Each relation now uses
 * ->withoutGlobalScope(SoftDeletingScope::class) so history keeps showing
 * the company name even after the Prospect is archived.
 */
class ProspectTrashedRelationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_call_record_still_resolves_a_soft_deleted_prospect(): void
    {
        $prospect = Prospect::factory()->create(['company_name' => 'Sunrise Plastics']);
        $call = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $prospect->assigned_to,
            'called_at' => now(),
            'outcome' => CallOutcome::NoAnswer,
        ]);

        $prospect->delete();

        $this->assertNotNull($call->fresh()->prospect);
        $this->assertSame('Sunrise Plastics', $call->fresh()->prospect->company_name);
    }

    public function test_follow_up_still_resolves_a_soft_deleted_prospect(): void
    {
        $prospect = Prospect::factory()->create(['company_name' => 'Sunrise Plastics']);
        $call = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $prospect->assigned_to,
            'called_at' => now(),
            'outcome' => CallOutcome::NoAnswer,
        ]);
        $followUp = $call->fresh()->followUp;

        $prospect->delete();

        $this->assertNotNull($followUp->fresh()->prospect);
        $this->assertSame('Sunrise Plastics', $followUp->fresh()->prospect->company_name);
    }

    public function test_appointment_still_resolves_a_soft_deleted_prospect(): void
    {
        $prospect = Prospect::factory()->create(['company_name' => 'Sunrise Plastics']);
        $call = CallRecord::create([
            'prospect_id' => $prospect->id,
            'user_id' => $prospect->assigned_to,
            'called_at' => now(),
            'outcome' => CallOutcome::AppointmentSet,
        ]);
        $appointment = $call->fresh()->appointment;

        $prospect->delete();

        $this->assertNotNull($appointment->fresh()->prospect);
        $this->assertSame('Sunrise Plastics', $appointment->fresh()->prospect->company_name);
    }

    public function test_lead_still_resolves_a_soft_deleted_prospect(): void
    {
        $prospect = Prospect::factory()->create(['company_name' => 'Sunrise Plastics']);
        $lead = Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $prospect->assigned_to,
            'created_by' => $prospect->created_by,
            'stage' => LeadStage::RequirementCollection,
            'temperature' => 'warm',
        ]);

        $prospect->delete();

        $this->assertNotNull($lead->fresh()->prospect);
        $this->assertSame('Sunrise Plastics', $lead->fresh()->prospect->company_name);
    }

    public function test_proposal_still_resolves_a_soft_deleted_prospect(): void
    {
        $prospect = Prospect::factory()->create(['company_name' => 'Sunrise Plastics']);
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

        $prospect->delete();

        $this->assertNotNull($proposal->fresh()->prospect);
        $this->assertSame('Sunrise Plastics', $proposal->fresh()->prospect->company_name);
    }
}
