<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Phase 4A-3.1: the five recordable client responses to a Sent
 * ProposalVersion (Master BA Specification Phase 4A-3 addendum,
 * PHASE4_OUTCOME_CUTOVER_GATE item 1). Recording behavior (Accepted -> Won,
 * Rejected -> Lost, etc.) is owned by the not-yet-implemented
 * ProposalClientResponseService (4A-3.4) — this enum is schema/vocabulary
 * only in 4A-3.1.
 */
enum ProposalClientResponseType: string implements HasColor, HasLabel
{
    case Accepted = 'accepted';
    case RevisionRequested = 'revision_requested';
    case MoreTime = 'more_time';
    case Rejected = 'rejected';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Accepted => 'Accepted',
            self::RevisionRequested => 'Revision Requested',
            self::MoreTime => 'More Time / Decision Pending',
            self::Rejected => 'Rejected / No Further Progression',
            self::Other => 'Other',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Accepted => 'success',
            self::RevisionRequested => 'info',
            self::MoreTime => 'warning',
            self::Rejected => 'danger',
            self::Other => 'gray',
        };
    }
}
