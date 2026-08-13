<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Stable internal call outcome values.
 *
 * IMPORTANT: routing logic (which downstream records an outcome creates)
 * must only ever key off these ->value identifiers, never off the display
 * label. Labels can be renamed freely without touching business logic.
 *
 * The routesTo*() helpers below are the single source of truth for call
 * routing and are consumed by App\Services\CallRoutingService. See
 * AGENTS.md sections 14-16 and 46.
 */
enum CallOutcome: string implements HasColor, HasLabel
{
    case NoAnswer = 'no_answer';
    case SwitchedOff = 'switched_off';
    case NotReachable = 'not_reachable';
    case CallbackRequested = 'callback_requested';
    case AppointmentSet = 'appointment_set';
    case FutureOpportunity = 'future_opportunity';
    case RequirementIdentified = 'requirement_identified';

    public function getLabel(): string
    {
        return match ($this) {
            self::NoAnswer => 'No Answer',
            self::SwitchedOff => 'Switched Off',
            self::NotReachable => 'Not Reachable',
            self::CallbackRequested => 'Callback Requested',
            self::AppointmentSet => 'Appointment Set',
            self::FutureOpportunity => 'No Current Requirement / Future Opportunity',
            self::RequirementIdentified => 'Requirement Identified',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::NoAnswer, self::SwitchedOff, self::NotReachable => 'gray',
            self::CallbackRequested => 'warning',
            self::AppointmentSet, self::FutureOpportunity => 'info',
            self::RequirementIdentified => 'success',
        };
    }

    /** Outcomes that route to the Follow-Ups panel. */
    public function routesToFollowUp(): bool
    {
        return in_array($this, [
            self::NoAnswer,
            self::SwitchedOff,
            self::NotReachable,
            self::CallbackRequested,
        ], true);
    }

    /** Outcomes that route to the Appointment Call Sheet. */
    public function routesToAppointment(): bool
    {
        return in_array($this, [
            self::AppointmentSet,
            self::FutureOpportunity,
            self::RequirementIdentified,
        ], true);
    }

    /** Outcomes that route to the Lead Sheet (in addition to an Appointment). */
    public function routesToLead(): bool
    {
        return $this === self::RequirementIdentified;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->getLabel()])->all();
    }
}
