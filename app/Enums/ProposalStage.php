<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * PROVISIONAL — stage names and order are not yet confirmed by the business
 * (see AGENTS.md section 61, Question 3). This tracks the internal
 * preparation/sending process of a Proposal, which is a separate concept
 * from ProposalOutcome (the final Won/Hold/Lost decision).
 */
enum ProposalStage: string implements HasColor, HasLabel
{
    case BeingPrepared = 'being_prepared';
    case Sent = 'sent';
    case CustomerAccepted = 'customer_accepted';
    case CustomerRejected = 'customer_rejected';

    public function getLabel(): string
    {
        return match ($this) {
            self::BeingPrepared => 'Proposal Being Prepared',
            self::Sent => 'Proposal Sent',
            self::CustomerAccepted => 'Customer Accepted',
            self::CustomerRejected => 'Customer Rejected',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::BeingPrepared => 'gray',
            self::Sent => 'info',
            self::CustomerAccepted => 'success',
            self::CustomerRejected => 'danger',
        };
    }

    /**
     * Terminal stages for the Pipeline Board's cross-lane drag rule only —
     * nothing else in the app reads this today (Proposal's own
     * staleness/tab logic keys off the separate `outcome` field, not
     * `stage` — see ProposalOutcome::isTerminalForStaleness()). Mirrors
     * AppointmentStage::isTerminal()/LeadStage::isTerminal()'s shape so the
     * board can treat all four "stage progression" resources uniformly when
     * deciding whether a dragged source card still needs resolving forward.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::CustomerAccepted, self::CustomerRejected], true);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->getLabel()])->all();
    }
}
