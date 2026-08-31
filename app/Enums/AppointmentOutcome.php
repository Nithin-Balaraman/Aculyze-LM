<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Phase 2: the business result recorded when an Appointment is completed —
 * separate from App\Enums\AppointmentStatus (lifecycle) and never
 * fabricated by the legacy-backfill command (every legacy row that maps to
 * AppointmentStatus::Completed is left with a NULL outcome — see
 * App\Console\Commands\BackfillLeadAppointmentStatus).
 *
 * Only App\Enums\AppointmentOutcome::RequirementIdentified creates/moves to
 * a Lead — no other outcome does, per the approved Master BA rule.
 */
enum AppointmentOutcome: string implements HasColor, HasLabel
{
    case FollowUpRequired = 'follow_up_required';
    case RequirementIdentified = 'requirement_identified';
    case AnotherAppointmentRequired = 'another_appointment_required';
    case DemoRequired = 'demo_required';
    case ProposalRequired = 'proposal_required';
    case NoCurrentRequirement = 'no_current_requirement';

    public function getLabel(): string
    {
        return match ($this) {
            self::FollowUpRequired => 'Follow-Up Required',
            self::RequirementIdentified => 'Requirement Identified',
            self::AnotherAppointmentRequired => 'Another Appointment Required',
            self::DemoRequired => 'Demo Required',
            self::ProposalRequired => 'Proposal Required',
            self::NoCurrentRequirement => 'No Current Requirement',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::RequirementIdentified, self::ProposalRequired => 'success',
            self::FollowUpRequired, self::AnotherAppointmentRequired, self::DemoRequired => 'info',
            self::NoCurrentRequirement => 'gray',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->getLabel()])->all();
    }
}
