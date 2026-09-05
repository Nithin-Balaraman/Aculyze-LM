<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Phase 4A: a ProposalVersionLine's tax is one-or-more of these component
 * snapshots (Master BA Specification sections 3.3 and 6.3) — deliberately
 * never a single tax_id on the line itself, since Indian GST commonly
 * needs CGST+SGST together (intra-state) or IGST alone (inter-state),
 * which one tax rate cannot express.
 */
enum ProposalTaxComponentType: string implements HasLabel
{
    case Cgst = 'cgst';
    case Sgst = 'sgst';
    case Igst = 'igst';
    case Cess = 'cess';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cgst => 'CGST',
            self::Sgst => 'SGST',
            self::Igst => 'IGST',
            self::Cess => 'Cess',
            self::Other => 'Other',
        };
    }
}
