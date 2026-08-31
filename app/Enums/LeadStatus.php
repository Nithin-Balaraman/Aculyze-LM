<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Phase 2: the approved normalized Lead vocabulary (Master BA Functional
 * Requirements Specification), replacing App\Enums\LeadStage for all new
 * application logic. `stage` is kept, untouched, permanently readable as
 * historical/compatibility data — its retirement is an explicit future
 * decision, not part of Phase 2 (see the legacy-backfill command's
 * docblock for the exact conservative mapping used).
 *
 * A Lead does NOT become Overdue merely because time passes — only its
 * linked scheduled activities (Follow-Up/Appointment/Demo) do — so unlike
 * AppointmentStatus/DemoStatus, this single enum needs no separate
 * lifecycle/outcome split (see App\Support\Authorization\HierarchyVisibility
 * and the Phase 2 plan's status/outcome model section for the reasoning).
 *
 * Lost/reopening is deliberately NOT represented here — is_lost/
 * lost_at_stage/lost_reason/lost_at remain the separate, orthogonal
 * mechanism they already are on this model; folding Lost into this
 * vocabulary is an explicitly held, separate business decision.
 */
enum LeadStatus: string implements HasColor, HasLabel
{
    case RequirementCollection = 'requirement_collection';
    case MoreInformationRequired = 'more_information_required';
    case RequirementConfirmed = 'requirement_confirmed';
    case FollowUpRequired = 'follow_up_required';
    case AppointmentRequired = 'appointment_required';
    case DemoRequired = 'demo_required';
    case ProposalRequired = 'proposal_required';
    case NoCurrentProgression = 'no_current_progression';

    public function getLabel(): string
    {
        return match ($this) {
            self::RequirementCollection => 'Requirement Collection',
            self::MoreInformationRequired => 'More Information Required',
            self::RequirementConfirmed => 'Requirement Confirmed',
            self::FollowUpRequired => 'Follow-Up Required',
            self::AppointmentRequired => 'Appointment Required',
            self::DemoRequired => 'Demo Required',
            self::ProposalRequired => 'Proposal Required',
            self::NoCurrentProgression => 'No Current Progression',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::RequirementCollection => 'gray',
            self::MoreInformationRequired, self::FollowUpRequired => 'warning',
            self::RequirementConfirmed, self::AppointmentRequired, self::DemoRequired => 'info',
            self::ProposalRequired => 'success',
            self::NoCurrentProgression => 'gray',
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
