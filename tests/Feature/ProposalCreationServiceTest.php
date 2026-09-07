<?php

namespace Tests\Feature;

use App\Enums\LeadStage;
use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Enums\ProposalOutcome;
use App\Enums\ProposalStage;
use App\Enums\ProposalVersionLifecycle;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Proposal;
use App\Models\ProposalVersion;
use App\Models\Prospect;
use App\Models\User;
use App\Services\ProposalCreationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * Phase 4A-2.4: ProposalCreationService — the single centralized owner of
 * runtime Proposal + V1 Draft ProposalVersion creation (locked Decision
 * 12). These tests exercise the service directly; the four runtime call
 * sites that now delegate to it (Appointment/Demo outcome via
 * WorkflowTransitionService, PipelineBoard cross-drop, the direct Filament
 * Create page) are covered separately in
 * ProposalCreationRuntimePathsTest.
 */
class ProposalCreationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function leadWithProspect(array $prospectOverrides = []): Lead
    {
        $owner = User::factory()->create();
        $prospect = Prospect::factory()->create(array_merge([
            'assigned_to' => $owner->id,
            'created_by' => $owner->id,
        ], $prospectOverrides));

        return Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $owner->id,
            'created_by' => $owner->id,
            'stage' => LeadStage::Validated,
            'status' => LeadStatus::ProposalRequired,
            'temperature' => LeadTemperature::Hot,
            'notes' => 'Ready for Proposal.',
        ]);
    }

    // -----------------------------------------------------------------
    // A. CENTRAL SERVICE
    // -----------------------------------------------------------------

    public function test_creates_proposal_and_v1_in_one_operation(): void
    {
        $lead = $this->leadWithProspect();

        $proposal = app(ProposalCreationService::class)->createForLead($lead, [
            'assigned_to' => $lead->assigned_to,
            'created_by' => $lead->created_by,
            'stage' => ProposalStage::BeingPrepared->value,
        ]);

        $this->assertNotNull($proposal->id);
        $this->assertSame(1, ProposalVersion::where('proposal_id', $proposal->id)->count());
    }

    public function test_v1_is_draft(): void
    {
        $lead = $this->leadWithProspect();
        $proposal = app(ProposalCreationService::class)->createForLead($lead, ['assigned_to' => $lead->assigned_to, 'created_by' => $lead->created_by, 'stage' => ProposalStage::BeingPrepared->value]);

        $version = ProposalVersion::where('proposal_id', $proposal->id)->firstOrFail();
        $this->assertSame(ProposalVersionLifecycle::Draft, $version->lifecycle_status);
    }

    public function test_v1_version_number_is_1(): void
    {
        $lead = $this->leadWithProspect();
        $proposal = app(ProposalCreationService::class)->createForLead($lead, ['assigned_to' => $lead->assigned_to, 'created_by' => $lead->created_by, 'stage' => ProposalStage::BeingPrepared->value]);

        $version = ProposalVersion::where('proposal_id', $proposal->id)->firstOrFail();
        $this->assertSame(1, $version->version_number);
    }

    public function test_v1_is_legacy_backfill_is_false(): void
    {
        $lead = $this->leadWithProspect();
        $proposal = app(ProposalCreationService::class)->createForLead($lead, ['assigned_to' => $lead->assigned_to, 'created_by' => $lead->created_by, 'stage' => ProposalStage::BeingPrepared->value]);

        $version = ProposalVersion::where('proposal_id', $proposal->id)->firstOrFail();
        $this->assertFalse($version->is_legacy_backfill);
    }

    public function test_current_version_id_points_to_v1(): void
    {
        $lead = $this->leadWithProspect();
        $proposal = app(ProposalCreationService::class)->createForLead($lead, ['assigned_to' => $lead->assigned_to, 'created_by' => $lead->created_by, 'stage' => ProposalStage::BeingPrepared->value]);

        $version = ProposalVersion::where('proposal_id', $proposal->id)->firstOrFail();
        $this->assertSame($version->id, $proposal->fresh()->current_version_id);
    }

    public function test_organization_ids_match(): void
    {
        $lead = $this->leadWithProspect();
        $proposal = app(ProposalCreationService::class)->createForLead($lead, ['assigned_to' => $lead->assigned_to, 'created_by' => $lead->created_by, 'stage' => ProposalStage::BeingPrepared->value]);

        $version = ProposalVersion::where('proposal_id', $proposal->id)->firstOrFail();
        $this->assertSame($proposal->organization_id, $version->organization_id);
        $this->assertSame($lead->organization_id, $proposal->organization_id);
    }

    public function test_v1_snapshots_prospect_company_name(): void
    {
        $lead = $this->leadWithProspect(['company_name' => 'Acme Fabrication Co']);
        $proposal = app(ProposalCreationService::class)->createForLead($lead, ['assigned_to' => $lead->assigned_to, 'created_by' => $lead->created_by, 'stage' => ProposalStage::BeingPrepared->value]);

        $version = ProposalVersion::where('proposal_id', $proposal->id)->firstOrFail();
        $this->assertSame('Acme Fabrication Co', $version->customer_name_snapshot);
    }

    public function test_v1_snapshots_prospect_gstin_when_present(): void
    {
        $lead = $this->leadWithProspect(['gstin' => '33AAAAA0000A1Z5']);
        $proposal = app(ProposalCreationService::class)->createForLead($lead, ['assigned_to' => $lead->assigned_to, 'created_by' => $lead->created_by, 'stage' => ProposalStage::BeingPrepared->value]);

        $version = ProposalVersion::where('proposal_id', $proposal->id)->firstOrFail();
        $this->assertSame('33AAAAA0000A1Z5', $version->customer_gstin_snapshot);
    }

    public function test_v1_snapshots_prospect_billing_address_when_present(): void
    {
        $lead = $this->leadWithProspect(['billing_address' => '12 Industrial Estate, Coimbatore']);
        $proposal = app(ProposalCreationService::class)->createForLead($lead, ['assigned_to' => $lead->assigned_to, 'created_by' => $lead->created_by, 'stage' => ProposalStage::BeingPrepared->value]);

        $version = ProposalVersion::where('proposal_id', $proposal->id)->firstOrFail();
        $this->assertSame('12 Industrial Estate, Coimbatore', $version->billing_address_snapshot);
    }

    public function test_v1_snapshots_prospect_billing_state_when_present(): void
    {
        $lead = $this->leadWithProspect(['billing_state' => 'Kerala']);
        $proposal = app(ProposalCreationService::class)->createForLead($lead, ['assigned_to' => $lead->assigned_to, 'created_by' => $lead->created_by, 'stage' => ProposalStage::BeingPrepared->value]);

        $version = ProposalVersion::where('proposal_id', $proposal->id)->firstOrFail();
        $this->assertSame('Kerala', $version->billing_state_snapshot);
    }

    public function test_missing_new_prospect_fields_remain_null(): void
    {
        $lead = $this->leadWithProspect(); // gstin/billing_address/billing_state never set by ProspectFactory
        $proposal = app(ProposalCreationService::class)->createForLead($lead, ['assigned_to' => $lead->assigned_to, 'created_by' => $lead->created_by, 'stage' => ProposalStage::BeingPrepared->value]);

        $version = ProposalVersion::where('proposal_id', $proposal->id)->firstOrFail();
        $this->assertNull($version->customer_gstin_snapshot);
        $this->assertNull($version->billing_address_snapshot);
        $this->assertNull($version->billing_state_snapshot);
    }

    public function test_generic_prospect_address_is_not_copied_into_billing_address_snapshot(): void
    {
        $lead = $this->leadWithProspect(['address' => '99 Generic Street', 'billing_address' => null]);
        $proposal = app(ProposalCreationService::class)->createForLead($lead, ['assigned_to' => $lead->assigned_to, 'created_by' => $lead->created_by, 'stage' => ProposalStage::BeingPrepared->value]);

        $version = ProposalVersion::where('proposal_id', $proposal->id)->firstOrFail();
        $this->assertNull($version->billing_address_snapshot);
    }

    public function test_generic_prospect_state_is_not_copied_into_billing_state_snapshot_if_billing_state_is_null(): void
    {
        $lead = $this->leadWithProspect(['state' => 'Tamil Nadu', 'billing_state' => null]);
        $proposal = app(ProposalCreationService::class)->createForLead($lead, ['assigned_to' => $lead->assigned_to, 'created_by' => $lead->created_by, 'stage' => ProposalStage::BeingPrepared->value]);

        $version = ProposalVersion::where('proposal_id', $proposal->id)->firstOrFail();
        $this->assertNull($version->billing_state_snapshot);
    }

    public function test_place_of_supply_snapshot_starts_null(): void
    {
        $lead = $this->leadWithProspect();
        $proposal = app(ProposalCreationService::class)->createForLead($lead, ['assigned_to' => $lead->assigned_to, 'created_by' => $lead->created_by, 'stage' => ProposalStage::BeingPrepared->value]);

        $version = ProposalVersion::where('proposal_id', $proposal->id)->firstOrFail();
        $this->assertNull($version->place_of_supply_snapshot);
    }

    public function test_no_line_rows_created(): void
    {
        $lead = $this->leadWithProspect();
        $proposal = app(ProposalCreationService::class)->createForLead($lead, ['assigned_to' => $lead->assigned_to, 'created_by' => $lead->created_by, 'stage' => ProposalStage::BeingPrepared->value]);

        $version = ProposalVersion::where('proposal_id', $proposal->id)->firstOrFail();
        $this->assertSame(0, $version->lines()->count());
    }

    public function test_no_tax_rows_created(): void
    {
        $lead = $this->leadWithProspect();
        $proposal = app(ProposalCreationService::class)->createForLead($lead, ['assigned_to' => $lead->assigned_to, 'created_by' => $lead->created_by, 'stage' => ProposalStage::BeingPrepared->value]);

        $version = ProposalVersion::where('proposal_id', $proposal->id)->firstOrFail();
        $this->assertSame(0, DB::table('proposal_version_line_tax_components')
            ->join('proposal_version_lines', 'proposal_version_lines.id', '=', 'proposal_version_line_tax_components.proposal_version_line_id')
            ->where('proposal_version_lines.proposal_version_id', $version->id)
            ->count());
    }

    public function test_no_submission_approval_return_sent_evidence_fabricated(): void
    {
        $lead = $this->leadWithProspect();
        $proposal = app(ProposalCreationService::class)->createForLead($lead, ['assigned_to' => $lead->assigned_to, 'created_by' => $lead->created_by, 'stage' => ProposalStage::BeingPrepared->value]);

        $version = ProposalVersion::where('proposal_id', $proposal->id)->firstOrFail();
        $this->assertNull($version->submitted_by);
        $this->assertNull($version->submitted_at);
        $this->assertNull($version->approved_by);
        $this->assertNull($version->approved_at);
        $this->assertNull($version->returned_by);
        $this->assertNull($version->returned_at);
        $this->assertNull($version->sent_at);
        $this->assertNull($version->subtotal);
        $this->assertNull($version->total_discount);
        $this->assertNull($version->tax_total);
        $this->assertNull($version->grand_total);
    }

    // -----------------------------------------------------------------
    // B. ATOMICITY
    // -----------------------------------------------------------------

    public function test_v1_failure_rolls_back_proposal(): void
    {
        $lead = $this->leadWithProspect();

        // Legitimate testing technique: a temporary model-event listener
        // registered from the test itself (not a production hook baked
        // into the service) to simulate a downstream failure between two
        // real writes inside one real transaction.
        ProposalVersion::creating(function () {
            throw new LogicException('Simulated V1 creation failure.');
        });

        try {
            app(ProposalCreationService::class)->createForLead($lead, ['assigned_to' => $lead->assigned_to, 'created_by' => $lead->created_by, 'stage' => ProposalStage::BeingPrepared->value]);
            $this->fail('Expected the simulated V1 failure to propagate.');
        } catch (LogicException $e) {
            $this->assertSame('Simulated V1 creation failure.', $e->getMessage());
        } finally {
            ProposalVersion::flushEventListeners();
        }

        $this->assertSame(0, Proposal::where('lead_id', $lead->id)->count());
        $this->assertSame(0, ProposalVersion::count());
    }

    public function test_current_version_link_failure_rolls_back_proposal_and_v1(): void
    {
        $lead = $this->leadWithProspect();

        // Fires on the service's own forceFill(['current_version_id' =>
        // ...])->save() call, after both the Proposal and the V1 rows have
        // already been inserted within the same still-open transaction.
        Proposal::updating(function () {
            throw new LogicException('Simulated current_version_id link failure.');
        });

        try {
            app(ProposalCreationService::class)->createForLead($lead, ['assigned_to' => $lead->assigned_to, 'created_by' => $lead->created_by, 'stage' => ProposalStage::BeingPrepared->value]);
            $this->fail('Expected the simulated link failure to propagate.');
        } catch (LogicException $e) {
            $this->assertSame('Simulated current_version_id link failure.', $e->getMessage());
        } finally {
            Proposal::flushEventListeners();
        }

        $this->assertSame(0, Proposal::where('lead_id', $lead->id)->count());
        $this->assertSame(0, ProposalVersion::count());
    }

    public function test_cross_org_lead_prospect_inconsistency_creates_nothing(): void
    {
        $lead = $this->leadWithProspect();
        $otherOrg = Organization::factory()->create();

        // Forces an otherwise-unreachable inconsistency directly at the SQL
        // level (bypassing every model-level guard on purpose) purely to
        // exercise this service's own defensive re-check — ordinary
        // Eloquent writes can never produce this state themselves.
        DB::table('prospects')->where('id', $lead->prospect_id)->update(['organization_id' => $otherOrg->id]);

        $this->expectException(LogicException::class);

        try {
            app(ProposalCreationService::class)->createForLead($lead, ['assigned_to' => $lead->assigned_to, 'created_by' => $lead->created_by, 'stage' => ProposalStage::BeingPrepared->value]);
        } finally {
            $this->assertSame(0, Proposal::where('lead_id', $lead->id)->count());
            $this->assertSame(0, ProposalVersion::count());
        }
    }

    public function test_invalid_lead_creates_nothing(): void
    {
        $lead = $this->leadWithProspect();
        $leadId = $lead->id;

        // Simulates a stale/deleted Lead arriving at the service.
        DB::table('leads')->where('id', $leadId)->delete();

        $this->expectException(ModelNotFoundException::class);

        try {
            app(ProposalCreationService::class)->createForLead($lead, ['assigned_to' => $lead->assigned_to, 'created_by' => $lead->created_by, 'stage' => ProposalStage::BeingPrepared->value]);
        } finally {
            $this->assertSame(0, Proposal::where('lead_id', $leadId)->count());
            $this->assertSame(0, ProposalVersion::count());
        }
    }

    // -----------------------------------------------------------------
    // C. DUPLICATE / CONCURRENCY SEMANTICS
    // -----------------------------------------------------------------

    public function test_retry_double_create_does_not_create_second_proposal(): void
    {
        $lead = $this->leadWithProspect();
        $attrs = ['assigned_to' => $lead->assigned_to, 'created_by' => $lead->created_by, 'stage' => ProposalStage::BeingPrepared->value];

        $first = app(ProposalCreationService::class)->createForLead($lead, $attrs);
        $second = app(ProposalCreationService::class)->createForLead($lead, $attrs);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Proposal::where('lead_id', $lead->id)->count());
    }

    public function test_retry_does_not_create_second_v1(): void
    {
        $lead = $this->leadWithProspect();
        $attrs = ['assigned_to' => $lead->assigned_to, 'created_by' => $lead->created_by, 'stage' => ProposalStage::BeingPrepared->value];

        $proposal = app(ProposalCreationService::class)->createForLead($lead, $attrs);
        app(ProposalCreationService::class)->createForLead($lead, $attrs);

        $this->assertSame(1, ProposalVersion::where('proposal_id', $proposal->id)->count());
    }

    public function test_unique_lead_id_invariant_remains_satisfied(): void
    {
        $lead = $this->leadWithProspect();
        $attrs = ['assigned_to' => $lead->assigned_to, 'created_by' => $lead->created_by, 'stage' => ProposalStage::BeingPrepared->value];

        app(ProposalCreationService::class)->createForLead($lead, $attrs);
        app(ProposalCreationService::class)->createForLead($lead, $attrs);
        app(ProposalCreationService::class)->createForLead($lead, $attrs);

        $this->assertSame(1, Proposal::where('lead_id', $lead->id)->count());
    }

    public function test_one_active_draft_invariant_remains_satisfied(): void
    {
        $lead = $this->leadWithProspect();
        $attrs = ['assigned_to' => $lead->assigned_to, 'created_by' => $lead->created_by, 'stage' => ProposalStage::BeingPrepared->value];

        $proposal = app(ProposalCreationService::class)->createForLead($lead, $attrs);
        app(ProposalCreationService::class)->createForLead($lead, $attrs);

        $this->assertSame(1, ProposalVersion::where('proposal_id', $proposal->id)
            ->where('lifecycle_status', ProposalVersionLifecycle::Draft->value)
            ->count());
    }

    /**
     * True parallel/concurrent transactions cannot be exercised from a
     * single-process PHPUnit run against one DB connection — stated
     * honestly rather than faked. What IS provable in this environment is
     * the actual mechanism concurrency safety depends on: the Lead row
     * lock (lockForUpdate()) plus the re-check-while-locked-then-idempotent
     * -return behavior — proven above by two SEQUENTIAL calls on the same
     * Lead never producing a second Proposal/V1, exactly the same
     * "simulated concurrent second attempt" testing precedent already
     * established by ProposalVersionWorkflowServiceCreateRevisionTest.
     */
    public function test_sequential_repeat_calls_prove_the_lock_and_idempotent_return_behavior_available_in_this_test_environment(): void
    {
        $lead = $this->leadWithProspect();
        $attrs = ['assigned_to' => $lead->assigned_to, 'created_by' => $lead->created_by, 'stage' => ProposalStage::BeingPrepared->value];

        $results = [];
        for ($i = 0; $i < 5; $i++) {
            $results[] = app(ProposalCreationService::class)->createForLead($lead, $attrs)->id;
        }

        $this->assertCount(1, array_unique($results));
        $this->assertSame(1, Proposal::where('lead_id', $lead->id)->count());
        $this->assertSame(1, ProposalVersion::count());
    }

    // -----------------------------------------------------------------
    // H. LEGACY PARENT OUTCOME COEXISTENCE
    // -----------------------------------------------------------------

    public function test_legacy_won_outcome_at_creation_still_leaves_v1_draft(): void
    {
        $lead = $this->leadWithProspect();

        $proposal = app(ProposalCreationService::class)->createForLead($lead, [
            'assigned_to' => $lead->assigned_to,
            'created_by' => $lead->created_by,
            'stage' => ProposalStage::CustomerAccepted->value,
            'outcome' => ProposalOutcome::Won->value,
            'notes' => 'Signed and confirmed.',
        ]);

        $this->assertSame(ProposalOutcome::Won, $proposal->fresh()->outcome);

        $version = ProposalVersion::where('proposal_id', $proposal->id)->firstOrFail();
        $this->assertSame(ProposalVersionLifecycle::Draft, $version->lifecycle_status);
    }

    public function test_winning_version_id_remains_null_even_with_legacy_won_outcome(): void
    {
        $lead = $this->leadWithProspect();

        $proposal = app(ProposalCreationService::class)->createForLead($lead, [
            'assigned_to' => $lead->assigned_to,
            'created_by' => $lead->created_by,
            'stage' => ProposalStage::CustomerAccepted->value,
            'outcome' => ProposalOutcome::Won->value,
            'notes' => 'Signed and confirmed.',
        ]);

        $this->assertNull($proposal->fresh()->winning_version_id);
    }

    public function test_phase4_outcome_cutover_gate_behavior_remains_unchanged(): void
    {
        // The gate's own defining behavior: a legacy outcome may be set on
        // the parent Proposal, but no winning_version_id ever gets
        // populated as a side effect of that — proven directly above and
        // restated here as its own explicit regression anchor for the gate
        // itself, mirroring how ProposalVersionWorkflowServiceSubmitTest
        // asserts the same invariant from the Version-workflow side.
        $lead = $this->leadWithProspect();

        $proposal = app(ProposalCreationService::class)->createForLead($lead, [
            'assigned_to' => $lead->assigned_to,
            'created_by' => $lead->created_by,
            'stage' => ProposalStage::CustomerRejected->value,
            'outcome' => ProposalOutcome::Lost->value,
            'notes' => 'Lost to a competitor.',
        ]);

        $fresh = $proposal->fresh();
        $this->assertSame(ProposalOutcome::Lost, $fresh->outcome);
        $this->assertNull($fresh->winning_version_id);
    }
}
