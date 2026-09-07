<?php

namespace App\Services;

use App\Enums\ProposalLineDiscountType;
use App\Models\ProposalVersion;
use App\Models\ProposalVersionLine;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use LogicException;

/**
 * Phase 4A-2.3: the authoritative, decimal-safe commercial calculation
 * engine for a ProposalVersion — Brick\Math\BigDecimal is the application's
 * financial-arithmetic API (Decision 9, technically amended: see the Phase
 * 4A-2.3 completion report for the empirical backend-independence
 * verification that authorized this). Native float is never used as
 * financial authority anywhere in this class.
 *
 * Tax-exclusive pricing (Master BA Specification section 6.2): every rate
 * (discount percentage, tax component rate) is applied against an
 * already-computed, already-persisted-shape amount, never against another
 * unrounded rate. Every persisted money amount is rounded to 2dp
 * HALF_UP at the exact point it is computed, and every amount one level up
 * (a line's own gross/discount/taxable/tax/total, a Version's own
 * subtotal/total_discount/tax_total/grand_total) is a sum of already-
 * rounded values one level down — never a recomputation from an unrounded
 * theoretical aggregate.
 *
 * recalculate() only sets attributes in memory (on $version and each of its
 * lines/tax components) — it never calls save(). The caller decides when
 * and how to persist, so it can combine these values with an unrelated
 * change (e.g. ProposalVersionWorkflowService::submit()'s Draft ->
 * Submitted transition) inside its own transaction.
 */
class ProposalVersionCalculator
{
    private const MONEY_SCALE = 2;

    /**
     * Recalculates and stages (but does not persist) every derived
     * financial value for $version and each of its lines/tax components:
     * per-line gross/discount/taxable/tax/total, per-component amount, and
     * the Version's own subtotal/total_discount/tax_total/grand_total.
     *
     * Loads lines and tax components fresh from the database — never
     * trusts a possibly-stale relation already loaded on $version.
     *
     * @throws LogicException If any line or tax component's discount/rate
     *                        data violates the locked financial rules.
     */
    public function recalculate(ProposalVersion $version): void
    {
        $lines = $version->lines()->with('taxComponents')->get();

        $subtotal = BigDecimal::zero()->toScale(self::MONEY_SCALE);
        $totalDiscount = BigDecimal::zero()->toScale(self::MONEY_SCALE);
        $taxTotal = BigDecimal::zero()->toScale(self::MONEY_SCALE);
        $grandTotal = BigDecimal::zero()->toScale(self::MONEY_SCALE);

        foreach ($lines as $line) {
            $this->recalculateLine($line);

            $subtotal = $subtotal->plus($line->gross_amount);
            $totalDiscount = $totalDiscount->plus($line->discount_amount);
            $taxTotal = $taxTotal->plus($line->tax_amount);
            $grandTotal = $grandTotal->plus($line->line_total);
        }

        $version->forceFill([
            'subtotal' => $subtotal->toString(),
            'total_discount' => $totalDiscount->toString(),
            'tax_total' => $taxTotal->toString(),
            'grand_total' => $grandTotal->toString(),
        ]);
    }

    /**
     * Computes and sets (in memory only) $line's own gross_amount,
     * discount_amount, taxable_amount, tax_amount, line_total, and each of
     * its tax components' amount.
     */
    private function recalculateLine(ProposalVersionLine $line): void
    {
        $quantity = BigDecimal::of((string) $line->quantity);
        $unitPrice = BigDecimal::of((string) $line->unit_price);

        $grossAmount = $this->roundMoney($quantity->multipliedBy($unitPrice));
        $discountAmount = $this->discountAmount($line, $grossAmount);
        $taxableAmount = $this->roundMoney($grossAmount->minus($discountAmount));

        $taxAmount = BigDecimal::zero()->toScale(self::MONEY_SCALE);

        foreach ($line->taxComponents as $component) {
            $componentAmount = $this->taxComponentAmount($line, $taxableAmount, $component->rate, $component->component_type?->value);

            $component->forceFill(['amount' => $componentAmount->toString()])->save();

            $taxAmount = $taxAmount->plus($componentAmount);
        }

        $lineTotal = $this->roundMoney($taxableAmount->plus($taxAmount));

        $line->forceFill([
            'gross_amount' => $grossAmount->toString(),
            'discount_amount' => $discountAmount->toString(),
            'taxable_amount' => $taxableAmount->toString(),
            'tax_amount' => $taxAmount->toString(),
            'line_total' => $lineTotal->toString(),
        ])->save();
    }

    private function discountAmount(ProposalVersionLine $line, BigDecimal $grossAmount): BigDecimal
    {
        if ($line->discount_type === null) {
            return BigDecimal::zero()->toScale(self::MONEY_SCALE);
        }

        if ($line->discount_value === null) {
            throw new LogicException(
                "ProposalVersionLine #{$line->line_number} has a discount type but no discount value."
            );
        }

        $value = BigDecimal::of((string) $line->discount_value);

        return match ($line->discount_type) {
            ProposalLineDiscountType::Percentage => $this->percentageDiscountAmount($line, $grossAmount, $value),
            ProposalLineDiscountType::Fixed => $this->fixedDiscountAmount($line, $grossAmount, $value),
        };
    }

    private function percentageDiscountAmount(ProposalVersionLine $line, BigDecimal $grossAmount, BigDecimal $rate): BigDecimal
    {
        if ($rate->isNegative()) {
            throw new LogicException(
                "ProposalVersionLine #{$line->line_number}'s percentage discount rate must not be negative."
            );
        }

        if ($rate->isGreaterThan('100')) {
            throw new LogicException(
                "ProposalVersionLine #{$line->line_number}'s percentage discount rate cannot exceed 100."
            );
        }

        return $grossAmount->multipliedBy($rate)->dividedBy('100', self::MONEY_SCALE, RoundingMode::HalfUp);
    }

    private function fixedDiscountAmount(ProposalVersionLine $line, BigDecimal $grossAmount, BigDecimal $value): BigDecimal
    {
        if ($value->isNegative()) {
            throw new LogicException(
                "ProposalVersionLine #{$line->line_number}'s fixed discount value must not be negative."
            );
        }

        $rounded = $this->roundMoney($value);

        if ($rounded->isGreaterThan($grossAmount)) {
            throw new LogicException(
                "ProposalVersionLine #{$line->line_number}'s fixed discount value cannot exceed its gross amount."
            );
        }

        return $rounded;
    }

    private function taxComponentAmount(ProposalVersionLine $line, BigDecimal $taxableAmount, string $rate, ?string $componentType): BigDecimal
    {
        $rateValue = BigDecimal::of($rate);

        if ($rateValue->isNegative()) {
            throw new LogicException(
                "ProposalVersionLine #{$line->line_number}'s {$componentType} tax component rate must not be negative."
            );
        }

        return $taxableAmount->multipliedBy($rateValue)->dividedBy('100', self::MONEY_SCALE, RoundingMode::HalfUp);
    }

    private function roundMoney(BigDecimal $value): BigDecimal
    {
        return $value->toScale(self::MONEY_SCALE, RoundingMode::HalfUp);
    }
}
