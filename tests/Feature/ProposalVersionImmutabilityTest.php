<?php

namespace Tests\Feature;

use App\Enums\ProposalVersionLifecycle;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Proposal;
use App\Models\ProposalVersion;
use App\Models\ProposalVersionLine;
use App\Models\ProposalVersionLineTaxComponent;
use App\Models\Prospect;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * Phase 4A-2.1 locked Decision 11: server-side immutability for
 * ProposalVersion (identity fields always frozen; commercial/customer
 * content frozen once the row's ORIGINAL lifecycle was already non-Draft)
 * and for ProposalVersionLine/ProposalVersionLineTaxComponent (create/
 * update/delete allowed only while the parent Version is Draft). Workflow-
 * metadata fields (lifecycle_status, supersession, submitted_*,
 * approved_*, returned_*, sent_at) are deliberately NOT part of the
 * commercial-content allowlist — they are written by 4A-2.2's controlled
 * services, not built here.
 */
class ProposalVersionImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    private function proposal(User $owner): Proposal
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id]);

        $lead = Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $owner->id,
            'created_by' => $owner->id,
            'stage' => 'validated',
            'temperature' => 'hot',
            'notes' => 'Validated in test fixture.',
        ]);

        return Proposal::create([
            'lead_id' => $lead->id,
            'prospect_id' => $prospect->id,
            'assigned_to' => $owner->id,
            'created_by' => $owner->id,
            'stage' => 'being_prepared',
        ]);
    }

    private function version(ProposalVersionLifecycle $lifecycle, ?Proposal $proposal = null): ProposalVersion
    {
        $owner = User::factory()->create();
        $proposal ??= $this->proposal($owner);

        return ProposalVersion::factory()->create([
            'proposal_id' => $proposal->id,
            'version_number' => 1,
            'lifecycle_status' => $lifecycle,
            'customer_name_snapshot' => 'Original Customer',
            'subtotal' => 1000,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function commercialFieldSample(): array
    {
        return [
            'customer_name_snapshot' => 'Changed Customer',
            'customer_gstin_snapshot' => '33AAAAA0000A1Z5',
            'billing_address_snapshot' => 'Changed billing address',
            'billing_state_snapshot' => 'Kerala',
            'place_of_supply_snapshot' => 'Kerala',
            'payment_terms' => 'Net 60',
            'validity_terms' => 'Valid for 60 days',
            'scope_notes' => 'Changed scope',
            'subtotal' => 5000,
            'total_discount' => 100,
            'tax_total' => 900,
            'grand_total' => 5800,
            'currency_code' => 'USD',
        ];
    }

    // ── C. Identity field immutability ──────────────────────────────────

    public function test_identity_fields_are_immutable_on_a_draft_version(): void
    {
        $version = $this->version(ProposalVersionLifecycle::Draft);
        $otherProposal = $this->proposal(User::factory()->create());

        $this->expectException(LogicException::class);
        $version->proposal_id = $otherProposal->id;
        $version->save();
    }

    /**
     * A mismatched organization_id alone would trip
     * EnforcesSameOrganizationRelations (registered earlier, and correctly
     * so — that guard is real and should still fire for a genuinely
     * cross-tenant reassignment). To isolate THIS guard specifically, move
     * organization_id and proposal_id together to a consistent SECOND
     * organization's own Proposal — EnforcesSameOrganizationRelations sees
     * no mismatch, so this identity guard is what actually rejects it.
     */
    public function test_organization_id_is_immutable_on_a_draft_version(): void
    {
        $version = $this->version(ProposalVersionLifecycle::Draft);

        $otherOrg = Organization::factory()->create();
        $otherOwner = Tenancy::runAs($otherOrg->id, fn () => User::factory()->create(['organization_id' => $otherOrg->id]));
        $otherProposal = Tenancy::runAs($otherOrg->id, fn () => $this->proposal($otherOwner));

        $this->assertNotSame($otherOrg->id, $version->organization_id);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('organization_id');
        $version->organization_id = $otherOrg->id;
        $version->proposal_id = $otherProposal->id;
        $version->save();
    }

    public function test_version_number_is_immutable_on_a_draft_version(): void
    {
        $version = $this->version(ProposalVersionLifecycle::Draft);

        $this->expectException(LogicException::class);
        $version->version_number = 2;
        $version->save();
    }

    public function test_is_legacy_backfill_is_immutable_on_a_draft_version(): void
    {
        $version = $this->version(ProposalVersionLifecycle::Draft);

        $this->expectException(LogicException::class);
        $version->is_legacy_backfill = ! $version->is_legacy_backfill;
        $version->save();
    }

    public function test_identity_fields_are_also_immutable_on_a_non_draft_version(): void
    {
        $version = $this->version(ProposalVersionLifecycle::Sent);

        $this->expectException(LogicException::class);
        $version->version_number = 2;
        $version->save();
    }

    // ── D. Commercial/customer content immutability ─────────────────────

    public function test_draft_version_can_still_edit_every_commercial_field(): void
    {
        $version = $this->version(ProposalVersionLifecycle::Draft);

        $version->forceFill($this->commercialFieldSample())->save();

        $fresh = $version->fresh();
        foreach ($this->commercialFieldSample() as $field => $value) {
            $this->assertEquals($value, is_numeric($value) ? (float) $fresh->{$field} : $fresh->{$field});
        }
    }

    /**
     * Data-driven: proves the FULL commercial-field allowlist is enforced
     * (not just one representative field) against an Approved version.
     */
    public function test_approved_version_rejects_every_commercial_field_mutation(): void
    {
        foreach ($this->commercialFieldSample() as $field => $value) {
            $version = $this->version(ProposalVersionLifecycle::Approved);

            try {
                $version->{$field} = $value;
                $version->save();
                $this->fail("Expected a LogicException when mutating '{$field}' on an Approved version.");
            } catch (LogicException $e) {
                $this->assertStringContainsString('frozen', $e->getMessage());
            }
        }
    }

    public function test_submitted_version_rejects_commercial_field_mutation(): void
    {
        $version = $this->version(ProposalVersionLifecycle::Submitted);

        $this->expectException(LogicException::class);
        $version->grand_total = 9999;
        $version->save();
    }

    public function test_sent_version_rejects_commercial_field_mutation(): void
    {
        $version = $this->version(ProposalVersionLifecycle::Sent);

        $this->expectException(LogicException::class);
        $version->customer_name_snapshot = 'Someone else';
        $version->save();
    }

    public function test_returned_for_revision_version_rejects_commercial_field_mutation(): void
    {
        $version = $this->version(ProposalVersionLifecycle::ReturnedForRevision);

        $this->expectException(LogicException::class);
        $version->payment_terms = 'Net 90';
        $version->save();
    }

    // ── G. Future workflow-metadata compatibility (guard-level only) ────

    /**
     * The commercial-content guard must not indiscriminately block every
     * field once non-Draft — workflow-metadata evidence fields remain
     * writable, since 4A-2.2's controlled services need to populate them.
     */
    public function test_workflow_metadata_fields_remain_writable_on_a_non_draft_version(): void
    {
        $version = $this->version(ProposalVersionLifecycle::Submitted);
        $seniorManager = User::factory()->create();

        $version->forceFill([
            'lifecycle_status' => ProposalVersionLifecycle::Approved,
            'approved_by' => $seniorManager->id,
            'approved_at' => now(),
            'approval_comment' => 'Looks good.',
        ])->save();

        $fresh = $version->fresh();
        $this->assertSame(ProposalVersionLifecycle::Approved, $fresh->lifecycle_status);
        $this->assertSame($seniorManager->id, $fresh->approved_by);
    }

    /**
     * The key nuance called out in the 4A-2.1 spec: a single save that
     * moves lifecycle_status Draft -> Submitted AND persists final
     * recalculated commercial values must succeed, since the guard checks
     * the ORIGINAL (pre-save) lifecycle, not the incoming one.
     */
    public function test_draft_to_submitted_transition_can_persist_recalculated_totals_in_the_same_save(): void
    {
        $version = $this->version(ProposalVersionLifecycle::Draft);
        $manager = User::factory()->create();

        $version->forceFill([
            'lifecycle_status' => ProposalVersionLifecycle::Submitted,
            'grand_total' => 12345.67,
            'submitted_by' => $manager->id,
            'submitted_at' => now(),
        ])->save();

        $fresh = $version->fresh();
        $this->assertSame(ProposalVersionLifecycle::Submitted, $fresh->lifecycle_status);
        $this->assertSame('12345.67', $fresh->grand_total);
        $this->assertSame($manager->id, $fresh->submitted_by);
    }

    // ── E. ProposalVersionLine immutability ──────────────────────────────

    public function test_a_line_can_be_created_updated_and_deleted_while_parent_is_draft(): void
    {
        $version = $this->version(ProposalVersionLifecycle::Draft);

        $line = ProposalVersionLine::create([
            'proposal_version_id' => $version->id,
            'line_number' => 1,
            'item_name' => 'Widget',
            'quantity' => 2,
            'unit_price' => 500,
        ]);

        $line->update(['item_name' => 'Widget Pro']);
        $this->assertSame('Widget Pro', $line->fresh()->item_name);

        $line->delete();
        $this->assertDatabaseMissing('proposal_version_lines', ['id' => $line->id]);
    }

    public function test_creating_a_line_on_a_non_draft_version_is_rejected(): void
    {
        $version = $this->version(ProposalVersionLifecycle::Sent);

        $this->expectException(LogicException::class);
        ProposalVersionLine::create([
            'proposal_version_id' => $version->id,
            'line_number' => 1,
            'item_name' => 'Widget',
            'quantity' => 1,
            'unit_price' => 100,
        ]);
    }

    public function test_updating_a_line_on_a_non_draft_version_is_rejected(): void
    {
        $draft = $this->version(ProposalVersionLifecycle::Draft);
        $line = ProposalVersionLine::create([
            'proposal_version_id' => $draft->id,
            'line_number' => 1,
            'item_name' => 'Widget',
            'quantity' => 1,
            'unit_price' => 100,
        ]);

        // Freeze the parent directly at the DB layer (bypassing the
        // ProposalVersion guard, which isn't under test here) to isolate
        // the Line guard's own behavior against an already-non-Draft parent.
        DB::table('proposal_versions')->where('id', $draft->id)->update(['lifecycle_status' => 'sent']);

        $this->expectException(LogicException::class);
        $line->update(['item_name' => 'Changed']);
    }

    public function test_deleting_a_line_on_a_non_draft_version_is_rejected(): void
    {
        $draft = $this->version(ProposalVersionLifecycle::Draft);
        $line = ProposalVersionLine::create([
            'proposal_version_id' => $draft->id,
            'line_number' => 1,
            'item_name' => 'Widget',
            'quantity' => 1,
            'unit_price' => 100,
        ]);

        DB::table('proposal_versions')->where('id', $draft->id)->update(['lifecycle_status' => 'approved']);

        $this->expectException(LogicException::class);
        $line->delete();
    }

    // ── F. ProposalVersionLineTaxComponent immutability ──────────────────

    public function test_a_tax_component_can_be_created_updated_and_deleted_while_parent_line_version_is_draft(): void
    {
        $version = $this->version(ProposalVersionLifecycle::Draft);
        $line = ProposalVersionLine::create([
            'proposal_version_id' => $version->id,
            'line_number' => 1,
            'item_name' => 'Widget',
            'quantity' => 1,
            'unit_price' => 100,
        ]);

        $component = ProposalVersionLineTaxComponent::create([
            'proposal_version_line_id' => $line->id,
            'component_type' => 'cgst',
            'rate' => 9,
            'amount' => 9,
        ]);

        $component->update(['rate' => 14]);
        $this->assertEquals(14, $component->fresh()->rate);

        $component->delete();
        $this->assertDatabaseMissing('proposal_version_line_tax_components', ['id' => $component->id]);
    }

    public function test_creating_a_tax_component_on_a_non_draft_parent_is_rejected(): void
    {
        $version = $this->version(ProposalVersionLifecycle::Draft);
        $line = ProposalVersionLine::create([
            'proposal_version_id' => $version->id,
            'line_number' => 1,
            'item_name' => 'Widget',
            'quantity' => 1,
            'unit_price' => 100,
        ]);

        DB::table('proposal_versions')->where('id', $version->id)->update(['lifecycle_status' => 'sent']);

        $this->expectException(LogicException::class);
        ProposalVersionLineTaxComponent::create([
            'proposal_version_line_id' => $line->id,
            'component_type' => 'cgst',
            'rate' => 9,
            'amount' => 9,
        ]);
    }

    public function test_updating_a_tax_component_on_a_non_draft_parent_is_rejected(): void
    {
        $version = $this->version(ProposalVersionLifecycle::Draft);
        $line = ProposalVersionLine::create([
            'proposal_version_id' => $version->id,
            'line_number' => 1,
            'item_name' => 'Widget',
            'quantity' => 1,
            'unit_price' => 100,
        ]);
        $component = ProposalVersionLineTaxComponent::create([
            'proposal_version_line_id' => $line->id,
            'component_type' => 'cgst',
            'rate' => 9,
            'amount' => 9,
        ]);

        DB::table('proposal_versions')->where('id', $version->id)->update(['lifecycle_status' => 'approved']);

        $this->expectException(LogicException::class);
        $component->update(['rate' => 18]);
    }

    public function test_deleting_a_tax_component_on_a_non_draft_parent_is_rejected(): void
    {
        $version = $this->version(ProposalVersionLifecycle::Draft);
        $line = ProposalVersionLine::create([
            'proposal_version_id' => $version->id,
            'line_number' => 1,
            'item_name' => 'Widget',
            'quantity' => 1,
            'unit_price' => 100,
        ]);
        $component = ProposalVersionLineTaxComponent::create([
            'proposal_version_line_id' => $line->id,
            'component_type' => 'cgst',
            'rate' => 9,
            'amount' => 9,
        ]);

        DB::table('proposal_versions')->where('id', $version->id)->update(['lifecycle_status' => 'returned_for_revision']);

        $this->expectException(LogicException::class);
        $component->delete();
    }
}
