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
    case ConcernedPersonNotAvailable = 'concerned_person_not_available';
    case ProfileRequested = 'profile_requested';
    case AppointmentSet = 'appointment_set';
    case FutureOpportunity = 'future_opportunity';
    case RequirementIdentified = 'requirement_identified';
    case Others = 'others';

    public function getLabel(): string
    {
        return match ($this) {
            self::NoAnswer => 'No Answer',
            self::SwitchedOff => 'Switched Off',
            self::NotReachable => 'Not Reachable',
            self::CallbackRequested => 'Callback Requested',
            self::ConcernedPersonNotAvailable => 'Concerned Person Not Available',
            self::ProfileRequested => 'Profile Requested',
            self::AppointmentSet => 'Appointment Set',
            self::FutureOpportunity => 'No Current Requirement / Future Opportunity',
            self::RequirementIdentified => 'Requirement Identified',
            self::Others => 'Others',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::NoAnswer, self::SwitchedOff, self::NotReachable, self::Others => 'gray',
            self::CallbackRequested, self::ConcernedPersonNotAvailable, self::ProfileRequested => 'warning',
            self::AppointmentSet, self::FutureOpportunity => 'info',
            self::RequirementIdentified => 'success',
        };
    }

    /**
     * Outcomes that ALWAYS route to the Follow-Ups panel unconditionally.
     * Phase 3: only Callback Requested remains here. No Answer / Switched
     * Off / Not Reachable no longer auto-create a Follow-Up at all (they
     * remain plain Calls, notes optional, no automatic next activity).
     * Concerned Person Not Available and Profile Requested were also
     * removed — see routesToConditionalFollowUp() below.
     */
    public function routesToFollowUp(): bool
    {
        return $this === self::CallbackRequested;
    }

    /**
     * Phase 3: outcomes whose Follow-Up creation is conditional on the
     * caller actually supplying explicit Follow-Up data (`follow_up_at` +
     * `reason`) rather than always firing — see
     * App\Services\CallRoutingService::route(). Concerned Person Not
     * Available only creates a Follow-Up when a real callback is explicitly
     * agreed; Profile Requested's Follow-Up is optional/intentional only.
     */
    public function routesToConditionalFollowUp(): bool
    {
        return in_array($this, [
            self::ConcernedPersonNotAvailable,
            self::ProfileRequested,
        ], true);
    }

    /**
     * Outcomes that route to the Appointment Call Sheet. Phase 3:
     * RequirementIdentified was removed — creating an Appointment merely
     * because a requirement was identified was a business conflict (it
     * also created a Lead); Requirement Identified now creates a Lead only,
     * and an Appointment is scheduled separately if/when the user chooses.
     */
    public function routesToAppointment(): bool
    {
        return $this === self::AppointmentSet;
    }

    /**
     * These route nowhere — no Follow-Up, Appointment, or Lead is created.
     * The Call Record itself (and, for Others, its mandatory Notes — see
     * CallRecordResource::form()) is the only record of it, surfaced via
     * the "History" tab on the Call Records page instead (see
     * ListCallRecords::getTabs()).
     *
     * Note: Others' actual downstream creation (if any) is now driven by
     * CallNextAction, not by this method — see CallRoutingService::route().
     */
    public function routesNowhere(): bool
    {
        return in_array($this, [
            self::FutureOpportunity,
            self::Others,
        ], true);
    }

    /** Outcomes that route to the Lead Sheet (in addition to an Appointment). */
    public function routesToLead(): bool
    {
        return $this === self::RequirementIdentified;
    }

    /**
     * Whether logging a call with this outcome requires Notes — every
     * outcome where a real conversation actually happened, so there's
     * something worth documenting. Excludes only the three outcomes that
     * mean the call never connected at all (nothing to document). Notes
     * being mandatory here was previously scoped to just Others (the
     * catch-all with no defined routing); this is the single source of
     * truth CallRecordResource::form() and CallRecord's own model guard
     * both key off, same as every other routesTo*() classification here.
     */
    public function requiresNotes(): bool
    {
        return ! in_array($this, [
            self::NoAnswer,
            self::SwitchedOff,
            self::NotReachable,
        ], true);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->getLabel()])->all();
    }
}
