<?php

namespace Tests\Feature;

use App\Enums\ProposalLineDiscountType;
use App\Enums\ProposalVersionLifecycle;
use App\Enums\UserRole;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\ProposalVersion;
use App\Models\ProposalVersionLine;
use App\Models\ProposalVersionLineTaxComponent;
use App\Models\Prospect;
use App\Models\User;
use App\Services\ProposalVersionCalculator;
use App\Services\ProposalVersionWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * Phase 4A-2.3: the financial half of Submit — authoritative Brick\Math
 * recalculation (ProposalVersionCalculator) runs inside submit()'s existing
 * transaction, after structural validation and before the Draft ->
 * Submitted transition freezes. Structural-only Submit behavior remains
 * covered by ProposalVersionWorkflowServiceSubmitTest; this file is
 * strictly about the financial validation/recalculation 4A-2.2 deferred.
 */
class ProposalVersionWorkflowServiceSubmitFinancialValidationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{employee: User, manager: User} */
    private function hierarchy(): array
    {
        $seniorManager = User::factory()->admin()->create();
        $manager = User::factory()->create(['role' => UserRole::Manager, 'manager_id' => $seniorManager->id]);
        $employee = User::factory()->create(['role' => UserRole::Employee, 'manager_id' => $manager->id]);

        return compact('employee', 'manager');
    }

    private function proposalFor(User $employee): Proposal
    {
        $prospect = Prospect::factory()->create(['assigned_to' => $employee->id, 'created_by' => $employee->id]);

        $lead = Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => 'validated',
            'temperature' => 'hot',
            'notes' => 'Validated in test fixture.',
        ]);

        return Proposal::create([
            'lead_id' => $lead->id,
            'prospect_id' => $prospect->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'stage' => 'being_prepared',
        ]);
    }

    /** A structurally-complete Draft, with one line, ready for the caller to add discount/tax data to. */
    private function completeDraft(Proposal $proposal): ProposalVersion
    {
        $version = ProposalVersion::factory()->create([
            'proposal_id' => $proposal->id,
            'version_number' => 1,
            'lifecycle_status' => ProposalVersionLifecycle::Draft,
            'customer_name_snapshot' => 'Acme Corp',
        ]);

        ProposalVersionLine::create([
            'proposal_version_id' => $version->id,
            'line_number' => 1,
            'item_name' => 'Widget',
            'quantity' => 2,
            'unit_price' => 500,
        ]);

        $proposal->forceFill(['current_version_id' => $version->id])->save();

        return $version->fresh();
    }

    public function test_submit_recalculates_and_persists_totals_before_freezing_to_submitted(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->completeDraft($proposal);

        app(ProposalVersionWorkflowService::class)->submit($version, $manager);

        $fresh = $version->fresh(['lines']);
        $this->assertSame(ProposalVersionLifecycle::Submitted, $fresh->lifecycle_status);
        $this->assertSame('1000.00', $fresh->subtotal);
        $this->assertSame('0.00', $fresh->total_discount);
        $this->assertSame('0.00', $fresh->tax_total);
        $this->assertSame('1000.00', $fresh->grand_total);
        $this->assertSame('1000.00', $fresh->lines->first()->gross_amount);
        $this->assertSame('1000.00', $fresh->lines->first()->line_total);
    }

    public function test_submit_recalculates_discount_and_tax_and_persists_correct_totals(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->completeDraft($proposal);
        $version->forceFill([
            'billing_state_snapshot' => 'Tamil Nadu',
            'place_of_supply_snapshot' => 'Tamil Nadu',
        ])->save();

        $line = ProposalVersionLine::query()->where('proposal_version_id', $version->id)->firstOrFail();
        $line->forceFill([
            'discount_type' => ProposalLineDiscountType::Percentage,
            'discount_value' => '10',
        ])->save();

        ProposalVersionLineTaxComponent::create([
            'proposal_version_line_id' => $line->id,
            'component_type' => 'cgst',
            'rate' => '9',
            'amount' => '0',
        ]);
        ProposalVersionLineTaxComponent::create([
            'proposal_version_line_id' => $line->id,
            'component_type' => 'sgst',
            'rate' => '9',
            'amount' => '0',
        ]);

        app(ProposalVersionWorkflowService::class)->submit($version->fresh(), $manager);

        // gross 1000, discount 10% = 100, taxable = 900, tax 9%+9% of 900 = 81+81=162, total = 1062
        $fresh = $version->fresh(['lines.taxComponents']);
        $this->assertSame('1000.00', $fresh->subtotal);
        $this->assertSame('100.00', $fresh->total_discount);
        $this->assertSame('162.00', $fresh->tax_total);
        $this->assertSame('1062.00', $fresh->grand_total);
    }

    public function test_percentage_discount_over_100_prevents_submit(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->completeDraft($proposal);
        ProposalVersionLine::query()->where('proposal_version_id', $version->id)->update([
            'discount_type' => ProposalLineDiscountType::Percentage->value,
            'discount_value' => '150',
        ]);

        $this->expectException(LogicException::class);
        app(ProposalVersionWorkflowService::class)->submit($version->fresh(), $manager);
    }

    public function test_negative_discount_prevents_submit(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->completeDraft($proposal);
        ProposalVersionLine::query()->where('proposal_version_id', $version->id)->update([
            'discount_type' => ProposalLineDiscountType::Percentage->value,
            'discount_value' => '-5',
        ]);

        $this->expectException(LogicException::class);
        app(ProposalVersionWorkflowService::class)->submit($version->fresh(), $manager);
    }

    public function test_fixed_discount_exceeding_gross_prevents_submit(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->completeDraft($proposal);
        ProposalVersionLine::query()->where('proposal_version_id', $version->id)->update([
            'discount_type' => ProposalLineDiscountType::Fixed->value,
            'discount_value' => '5000',
        ]);

        $this->expectException(LogicException::class);
        app(ProposalVersionWorkflowService::class)->submit($version->fresh(), $manager);
    }

    public function test_negative_tax_rate_prevents_submit(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->completeDraft($proposal);
        $version->forceFill([
            'billing_state_snapshot' => 'Tamil Nadu',
            'place_of_supply_snapshot' => 'Tamil Nadu',
        ])->save();
        $line = ProposalVersionLine::query()->where('proposal_version_id', $version->id)->firstOrFail();

        ProposalVersionLineTaxComponent::create([
            'proposal_version_line_id' => $line->id,
            'component_type' => 'cgst',
            'rate' => '9',
            'amount' => '90',
        ]);
        ProposalVersionLineTaxComponent::query()->where('proposal_version_line_id', $line->id)->update(['rate' => '-9']);

        $this->expectException(LogicException::class);
        app(ProposalVersionWorkflowService::class)->submit($version->fresh(), $manager);
    }

    /** Invalid financial data must leave the Draft completely unchanged — no partial line/component saves survive. */
    public function test_failed_submit_due_to_invalid_financial_data_leaves_draft_completely_unchanged(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->completeDraft($proposal);

        $validLine = ProposalVersionLine::query()->where('proposal_version_id', $version->id)->firstOrFail();
        $originalGrossAmount = $validLine->gross_amount;
        $originalSubtotal = $version->subtotal;

        // A second line with invalid data — the whole Submit must fail and roll back.
        ProposalVersionLine::create([
            'proposal_version_id' => $version->id,
            'line_number' => 2,
            'item_name' => 'Gadget',
            'quantity' => 1,
            'unit_price' => 200,
            'discount_type' => ProposalLineDiscountType::Fixed,
            'discount_value' => '9999.00',
        ]);

        try {
            app(ProposalVersionWorkflowService::class)->submit($version->fresh(), $manager);
            $this->fail('Expected Submit to reject an invalid fixed discount.');
        } catch (LogicException) {
            // expected
        }

        $freshVersion = $version->fresh();
        $this->assertSame(ProposalVersionLifecycle::Draft, $freshVersion->lifecycle_status);
        $this->assertNull($freshVersion->submitted_by);
        $this->assertNull($freshVersion->submitted_at);
        $this->assertSame($originalSubtotal, $freshVersion->subtotal);
        $this->assertSame($originalGrossAmount, $validLine->fresh()->gross_amount);
    }

    public function test_failed_submit_due_to_invalid_financial_data_does_not_write_a_submitted_audit_event(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->completeDraft($proposal);
        ProposalVersionLine::query()->where('proposal_version_id', $version->id)->update([
            'discount_type' => ProposalLineDiscountType::Percentage->value,
            'discount_value' => '200',
        ]);

        try {
            app(ProposalVersionWorkflowService::class)->submit($version->fresh(), $manager);
        } catch (LogicException) {
            // expected
        }

        $this->assertSame(0, DB::table('audit_events')
            ->where('entity_type', 'ProposalVersion')
            ->where('entity_id', $version->id)
            ->where('action', 'proposal_version_submitted')
            ->count());
    }

    /** Non-Draft Versions can never be recalculated — the 4A-2.1 immutability guard rejects any attempted line save. */
    public function test_non_draft_version_lines_cannot_be_recalculated(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->completeDraft($proposal);
        app(ProposalVersionWorkflowService::class)->submit($version->fresh(), $manager);

        $submitted = $version->fresh();
        $this->assertSame(ProposalVersionLifecycle::Submitted, $submitted->lifecycle_status);

        $line = $submitted->lines()->firstOrFail();

        // Force a genuinely different recalculated value — a no-op
        // forceFill of byte-identical values would leave Eloquent's own
        // isDirty() check false and never even fire the model's updating
        // guard. Corrupt the persisted amount directly via a raw query
        // builder update (bypassing the guard, which only fires on
        // Eloquent save()), so recalculate() must produce a real change
        // against this now-non-Draft parent.
        DB::table('proposal_version_lines')->where('id', $line->id)->update(['gross_amount' => '1.00']);

        $this->expectException(LogicException::class);
        app(ProposalVersionCalculator::class)->recalculate($submitted);

        $this->assertSame('1.00', $line->fresh()->gross_amount);
    }

    public function test_submit_never_touches_parent_proposal_outcome_or_winning_version_after_financial_recalculation(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->completeDraft($proposal);

        app(ProposalVersionWorkflowService::class)->submit($version, $manager);

        $fresh = $proposal->fresh();
        $this->assertNull($fresh->outcome);
        $this->assertNull($fresh->winning_version_id);
    }

    public function test_manager_reviewed_fields_remain_null_after_financial_recalculation(): void
    {
        ['employee' => $employee, 'manager' => $manager] = $this->hierarchy();
        $proposal = $this->proposalFor($employee);
        $version = $this->completeDraft($proposal);

        app(ProposalVersionWorkflowService::class)->submit($version, $manager);

        $fresh = $version->fresh();
        $this->assertNull($fresh->manager_reviewed_by);
        $this->assertNull($fresh->manager_reviewed_at);
        $this->assertNull($fresh->manager_review_comment);
    }
}
