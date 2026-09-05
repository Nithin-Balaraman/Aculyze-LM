<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Phase 4A: the ProposalVersion lifecycle (Master BA Specification section
 * 4.1). Deliberately separate from supersession — `superseded_at`/
 * `superseded_by_version_id` on ProposalVersion itself are metadata layered
 * on top of whichever real lifecycle value a version reached, never a
 * lifecycle value of their own (section 15's explicit override: "Keep real
 * lifecycle status (e.g., Sent/Approved) and track supersession
 * separately"). A version whose lifecycle_status is Sent and that has
 * since been superseded by a newer version is still, truthfully, "Sent" —
 * never rewritten to some other value merely because it's no longer
 * current.
 */
enum ProposalVersionLifecycle: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case ReturnedForRevision = 'returned_for_revision';
    case Approved = 'approved';
    case Sent = 'sent';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted — Pending Final Approval',
            self::ReturnedForRevision => 'Returned for Revision',
            self::Approved => 'Approved',
            self::Sent => 'Sent',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Submitted => 'warning',
            self::ReturnedForRevision => 'danger',
            self::Approved => 'info',
            self::Sent => 'success',
        };
    }

    /**
     * Only a Draft is commercially editable (section 4.1's "Commercial
     * editability" column). Every other lifecycle value is frozen —
     * enforced here so 4A-2+ never has to re-derive this list.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
