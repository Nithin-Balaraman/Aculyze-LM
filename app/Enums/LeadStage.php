<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * PROVISIONAL — stage names and order are not yet confirmed by the business
 * (see AGENTS.md section 61, Question 2). Centralized here for the same
 * reason as AppointmentStage.
 */
enum LeadStage: string implements HasColor, HasLabel
{
    case RequirementCollection = 'requirement_collection';
    case DemoScheduledOrDone = 'demo_scheduled_or_done';
    case Validated = 'validated';

    public function getLabel(): string
    {
        return match ($this) {
            self::RequirementCollection => 'Requirement Collection',
            self::DemoScheduledOrDone => 'Demo Scheduled / Done',
            self::Validated => 'Validated',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::RequirementCollection => 'gray',
            self::DemoScheduledOrDone => 'info',
            self::Validated => 'success',
        };
    }

    /**
     * Terminal stages never appear as stale and are excluded from "active" lists.
     *
     * TODO(business decision): none of the current draft stages represents a
     * closed/lost Lead. Validated is treated as terminal here on the
     * assumption that once a Lead is validated, staleness is tracked via its
     * Proposal instead. Revisit if stakeholders add a "not proceeding" stage.
     */
    public function isTerminal(): bool
    {
        return $this === self::Validated;
    }

    /** A Lead can only move to Proposal once it reaches this stage. */
    public function isEligibleForProposal(): bool
    {
        return $this === self::Validated;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->getLabel()])->all();
    }
}
