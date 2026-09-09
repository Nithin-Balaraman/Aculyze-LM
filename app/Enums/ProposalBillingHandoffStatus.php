<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Phase 4A-3.1: only `Pending` exists — no external billing API call is
 * made in 4A-3 (locked Decision 15), so no other state is reachable yet.
 * Processing/Completed/Failed belong to whichever later phase integrates
 * the real billing provider; since this is a plain string column (not a
 * native DB enum type), adding those cases later needs no migration.
 */
enum ProposalBillingHandoffStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Pending => 'warning',
        };
    }
}
