<?php

namespace Tests\Feature;

use App\Enums\ProposalLineDiscountType;
use App\Enums\ProposalTaxComponentType;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\ProposalVersion;
use App\Models\ProposalVersionLine;
use App\Models\ProposalVersionLineTaxComponent;
use App\Models\Prospect;
use App\Models\User;
use App\Services\ProposalVersionCalculator;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Phase 4A-2.3: ProposalVersionCalculator — the authoritative,
 * Brick\Math\BigDecimal-based decimal-safe commercial calculation engine
 * (Decision 9, technically amended: see the Phase 4A-2.3 completion report
 * for the empirical backend-independence verification that authorized
 * replacing direct BCMath calls with Brick\Math as the application-level
 * decimal API).
 *
 * These tests call recalculate() directly against a Draft ProposalVersion's
 * real, persisted lines/tax components — never through
 * ProposalVersionWorkflowService::submit() — so they isolate calculation
 * correctness from Submit's own authorization/structural/transactional
 * concerns (covered separately in
 * ProposalVersionWorkflowServiceSubmitFinancialValidationTest).
 */
class ProposalVersionCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private function draftVersion(): ProposalVersion
    {
        $owner = User::factory()->create();
        $prospect = Prospect::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id]);

        $lead = Lead::create([
            'prospect_id' => $prospect->id,
            'assigned_to' => $owner->id,
            'created_by' => $owner->id,
            'stage' => 'validated',
            'temperature' => 'hot',
            'notes' => 'Validated in test fixture.',
        ]);

        $proposal = Proposal::create([
            'lead_id' => $lead->id,
            'prospect_id' => $prospect->id,
            'assigned_to' => $owner->id,
            'created_by' => $owner->id,
            'stage' => 'being_prepared',
        ]);

        return ProposalVersion::factory()->create([
            'proposal_id' => $proposal->id,
            'version_number' => 1,
        ]);
    }

    private function line(ProposalVersion $version, array $overrides = []): ProposalVersionLine
    {
        return ProposalVersionLine::create(array_merge([
            'proposal_version_id' => $version->id,
            'line_number' => ProposalVersionLine::query()->where('proposal_version_id', $version->id)->count() + 1,
            'item_name' => 'Widget',
            'quantity' => 2,
            'unit_price' => 500,
        ], $overrides));
    }

    private function recalculate(ProposalVersion $version): ProposalVersion
    {
        app(ProposalVersionCalculator::class)->recalculate($version);
        $version->save();

        return $version->fresh(['lines.taxComponents']);
    }

    // -----------------------------------------------------------------
    // Rounding edge cases (HALF-UP)
    // -----------------------------------------------------------------

    public function test_gross_amount_rounds_half_up_1_004_to_1_00(): void
    {
        // unit_price is schema-constrained to 2dp, so the 1.004 rounding
        // case is driven through a quantity(4dp) x unit_price(2dp) product
        // instead: 0.5020 * 2.00 = 1.004.
        $version = $this->draftVersion();
        $this->line($version, ['quantity' => '0.5020', 'unit_price' => '2.00']);

        $fresh = $this->recalculate($version);

        $this->assertSame('1.00', $fresh->lines->first()->gross_amount);
    }

    public function test_gross_amount_rounds_half_up_1_005_to_1_01(): void
    {
        $version = $this->draftVersion();
        $this->line($version, ['quantity' => '0.5025', 'unit_price' => '2.00']);

        $fresh = $this->recalculate($version);

        $this->assertSame('1.01', $fresh->lines->first()->gross_amount);
    }

    public function test_gross_amount_rounds_half_up_1_006_to_1_01(): void
    {
        $version = $this->draftVersion();
        $this->line($version, ['quantity' => '0.5030', 'unit_price' => '2.00']);

        $fresh = $this->recalculate($version);

        $this->assertSame('1.01', $fresh->lines->first()->gross_amount);
    }

    public function test_4dp_quantity_and_2dp_unit_price_multiply_correctly(): void
    {
        $version = $this->draftVersion();
        $this->line($version, ['quantity' => '3.1416', 'unit_price' => '499.99']);

        $fresh = $this->recalculate($version);

        // 3.1416 * 499.99 = 1570.768584 -> HALF_UP to 2dp = 1570.77
        $this->assertSame('1570.77', $fresh->lines->first()->gross_amount);
    }

    public function test_high_schema_valid_values_do_not_overflow_or_use_scientific_notation(): void
    {
        $version = $this->draftVersion();
        $this->line($version, ['quantity' => '9999.9999', 'unit_price' => '999999999999.99']);

        $fresh = $this->recalculate($version);

        $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $fresh->lines->first()->gross_amount);
        $this->assertStringNotContainsStringIgnoringCase('e', $fresh->lines->first()->gross_amount);
    }

    // -----------------------------------------------------------------
    // Percentage discount validation
    // -----------------------------------------------------------------

    public function test_zero_percent_discount_is_allowed(): void
    {
        $version = $this->draftVersion();
        $this->line($version, [
            'discount_type' => ProposalLineDiscountType::Percentage,
            'discount_value' => '0',
        ]);

        $fresh = $this->recalculate($version);

        $this->assertSame('0.00', $fresh->lines->first()->discount_amount);
        $this->assertSame('1000.00', $fresh->lines->first()->taxable_amount);
    }

    public function test_hundred_percent_discount_is_allowed(): void
    {
        $version = $this->draftVersion();
        $this->line($version, [
            'discount_type' => ProposalLineDiscountType::Percentage,
            'discount_value' => '100',
        ]);

        $fresh = $this->recalculate($version);

        $this->assertSame('1000.00', $fresh->lines->first()->discount_amount);
        $this->assertSame('0.00', $fresh->lines->first()->taxable_amount);
    }

    public function test_percentage_discount_over_100_is_rejected(): void
    {
        $version = $this->draftVersion();
        $this->line($version, [
            'discount_type' => ProposalLineDiscountType::Percentage,
            'discount_value' => '100.01',
        ]);

        $this->expectException(LogicException::class);
        app(ProposalVersionCalculator::class)->recalculate($version);
    }

    public function test_negative_percentage_discount_is_rejected(): void
    {
        $version = $this->draftVersion();
        $this->line($version, [
            'discount_type' => ProposalLineDiscountType::Percentage,
            'discount_value' => '-1',
        ]);

        $this->expectException(LogicException::class);
        app(ProposalVersionCalculator::class)->recalculate($version);
    }

    // -----------------------------------------------------------------
    // Fixed discount validation
    // -----------------------------------------------------------------

    public function test_fixed_discount_equal_to_gross_is_allowed(): void
    {
        $version = $this->draftVersion();
        $this->line($version, [
            'discount_type' => ProposalLineDiscountType::Fixed,
            'discount_value' => '1000.00',
        ]);

        $fresh = $this->recalculate($version);

        $this->assertSame('1000.00', $fresh->lines->first()->discount_amount);
        $this->assertSame('0.00', $fresh->lines->first()->taxable_amount);
    }

    public function test_fixed_discount_greater_than_gross_is_rejected(): void
    {
        $version = $this->draftVersion();
        $this->line($version, [
            'discount_type' => ProposalLineDiscountType::Fixed,
            'discount_value' => '1000.01',
        ]);

        $this->expectException(LogicException::class);
        app(ProposalVersionCalculator::class)->recalculate($version);
    }

    public function test_negative_fixed_discount_is_rejected(): void
    {
        $version = $this->draftVersion();
        $this->line($version, [
            'discount_type' => ProposalLineDiscountType::Fixed,
            'discount_value' => '-0.01',
        ]);

        $this->expectException(LogicException::class);
        app(ProposalVersionCalculator::class)->recalculate($version);
    }

    public function test_no_discount_type_means_zero_discount(): void
    {
        $version = $this->draftVersion();
        $this->line($version);

        $fresh = $this->recalculate($version);

        $this->assertSame('0.00', $fresh->lines->first()->discount_amount);
    }

    // -----------------------------------------------------------------
    // Tax component validation and arithmetic
    // -----------------------------------------------------------------

    public function test_zero_tax_rate_is_allowed(): void
    {
        $version = $this->draftVersion();
        $line = $this->line($version);
        ProposalVersionLineTaxComponent::create([
            'proposal_version_line_id' => $line->id,
            'component_type' => 'cgst',
            'rate' => '0',
            'amount' => '0',
        ]);

        $fresh = $this->recalculate($version);

        $this->assertSame('0.00', $fresh->lines->first()->taxComponents->first()->amount);
    }

    public function test_negative_tax_rate_is_rejected(): void
    {
        $version = $this->draftVersion();
        $line = $this->line($version);
        ProposalVersionLineTaxComponent::create([
            'proposal_version_line_id' => $line->id,
            'component_type' => 'cgst',
            'rate' => '-1',
            'amount' => '0',
        ]);

        $this->expectException(LogicException::class);
        app(ProposalVersionCalculator::class)->recalculate($version);
    }

    /** CGST + SGST manually supplied — no automatic GST-law inference. */
    public function test_cgst_plus_sgst_manually_supplied_arithmetic(): void
    {
        $version = $this->draftVersion();
        $line = $this->line($version); // gross/taxable = 1000.00
        ProposalVersionLineTaxComponent::create([
            'proposal_version_line_id' => $line->id,
            'component_type' => 'cgst',
            'rate' => '9.0000',
            'amount' => '0',
        ]);
        ProposalVersionLineTaxComponent::create([
            'proposal_version_line_id' => $line->id,
            'component_type' => 'sgst',
            'rate' => '9.0000',
            'amount' => '0',
        ]);

        $fresh = $this->recalculate($version);
        $components = $fresh->lines->first()->taxComponents;

        $this->assertSame('90.00', $components->firstWhere('component_type', ProposalTaxComponentType::Cgst)->amount);
        $this->assertSame('90.00', $components->firstWhere('component_type', ProposalTaxComponentType::Sgst)->amount);
        $this->assertSame('180.00', $fresh->lines->first()->tax_amount);
        $this->assertSame('1180.00', $fresh->lines->first()->line_total);
    }

    /** IGST manually supplied alone (inter-state) — no automatic law inference. */
    public function test_igst_manually_supplied_alone(): void
    {
        $version = $this->draftVersion();
        $line = $this->line($version);
        ProposalVersionLineTaxComponent::create([
            'proposal_version_line_id' => $line->id,
            'component_type' => 'igst',
            'rate' => '18.0000',
            'amount' => '0',
        ]);

        $fresh = $this->recalculate($version);

        $this->assertSame('180.00', $fresh->lines->first()->taxComponents->first()->amount);
        $this->assertSame('180.00', $fresh->lines->first()->tax_amount);
    }

    public function test_multiple_tax_components_sum_from_persisted_rounded_amounts(): void
    {
        $version = $this->draftVersion();
        $line = $this->line($version, ['quantity' => '1', 'unit_price' => '333.33']);
        ProposalVersionLineTaxComponent::create([
            'proposal_version_line_id' => $line->id,
            'component_type' => 'cgst',
            'rate' => '9.0000',
            'amount' => '0',
        ]);
        ProposalVersionLineTaxComponent::create([
            'proposal_version_line_id' => $line->id,
            'component_type' => 'sgst',
            'rate' => '9.0000',
            'amount' => '0',
        ]);

        $fresh = $this->recalculate($version);
        $freshLine = $fresh->lines->first();

        $componentSum = $freshLine->taxComponents->reduce(
            fn (string $carry, $component) => BigDecimal::of($carry)->plus($component->amount)->toString(),
            '0.00'
        );

        $this->assertSame($componentSum, $freshLine->tax_amount);
    }

    // -----------------------------------------------------------------
    // Caller-tampered derived amounts are always recalculated
    // -----------------------------------------------------------------

    public function test_caller_tampered_derived_amounts_are_recalculated_not_trusted(): void
    {
        $version = $this->draftVersion();
        $this->line($version, [
            'gross_amount' => '999999.99',
            'taxable_amount' => '1',
            'tax_amount' => '1',
            'line_total' => '1',
        ]);

        $fresh = $this->recalculate($version);

        $this->assertSame('1000.00', $fresh->lines->first()->gross_amount);
        $this->assertSame('1000.00', $fresh->lines->first()->taxable_amount);
        $this->assertSame('0.00', $fresh->lines->first()->tax_amount);
        $this->assertSame('1000.00', $fresh->lines->first()->line_total);
    }

    // -----------------------------------------------------------------
    // Multi-line rollups from persisted rounded values
    // -----------------------------------------------------------------

    public function test_version_totals_sum_persisted_rounded_line_values_across_multiple_lines(): void
    {
        $version = $this->draftVersion();
        $lineA = $this->line($version, ['quantity' => '3', 'unit_price' => '10.33']);
        $lineB = $this->line($version, [
            'quantity' => '2',
            'unit_price' => '50.00',
            'discount_type' => ProposalLineDiscountType::Percentage,
            'discount_value' => '10',
        ]);
        ProposalVersionLineTaxComponent::create([
            'proposal_version_line_id' => $lineA->id,
            'component_type' => 'igst',
            'rate' => '18.0000',
            'amount' => '0',
        ]);
        ProposalVersionLineTaxComponent::create([
            'proposal_version_line_id' => $lineB->id,
            'component_type' => 'igst',
            'rate' => '18.0000',
            'amount' => '0',
        ]);

        $fresh = $this->recalculate($version);

        // Line A: gross = 3 * 10.33 = 30.99, no discount, tax = 30.99*18/100 = 5.5782 -> 5.58, total = 36.57
        // Line B: gross = 2 * 50.00 = 100.00, discount 10% = 10.00, taxable = 90.00, tax = 90.00*18/100 = 16.20, total = 106.20
        $this->assertSame('130.99', $fresh->subtotal);
        $this->assertSame('10.00', $fresh->total_discount);
        $this->assertSame('21.78', $fresh->tax_total);
        $this->assertSame('142.77', $fresh->grand_total);

        $expectedGrandTotal = collect($fresh->lines)->reduce(
            fn (string $carry, $line) => BigDecimal::of($carry)->plus($line->line_total)->toString(),
            '0.00'
        );
        $this->assertSame($expectedGrandTotal, $fresh->grand_total);
    }

    /**
     * ProposalVersionFactory's own default subtotal ('1000.00') is
     * deliberately left untouched by the fixture here, so a DB re-read
     * before any save() proves recalculate() only staged the real
     * ('250.00') total in memory rather than persisting it itself.
     */
    public function test_recalculate_only_stages_version_totals_in_memory_and_does_not_itself_save_the_version(): void
    {
        $version = $this->draftVersion();
        $this->line($version, ['quantity' => '1', 'unit_price' => '250.00']);

        app(ProposalVersionCalculator::class)->recalculate($version);

        $this->assertSame('250.00', $version->subtotal);
        $this->assertTrue($version->isDirty('subtotal'));
        $this->assertSame('1000.00', $version->fresh()->subtotal);
    }
}
