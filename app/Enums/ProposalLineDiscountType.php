<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Phase 4A: a ProposalVersionLine's discount is either a percentage or a
 * fixed amount (Master BA Specification sections 3.3 and 6.2) — a fixed
 * amount can never exceed the line's own gross_amount (enforced by the
 * calculation engine in a later sub-phase, not here).
 */
enum ProposalLineDiscountType: string implements HasLabel
{
    case Percentage = 'percentage';
    case Fixed = 'fixed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Percentage => 'Percentage',
            self::Fixed => 'Fixed Amount',
        };
    }
}
