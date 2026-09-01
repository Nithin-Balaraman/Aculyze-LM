<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Phase 3: the approved, narrow set of next-step actions a Proposal can
 * generate — deliberately NOT reusing AppointmentOutcome/DemoNextAction,
 * since Proposal's valid continuations are a smaller, distinct set. Pure
 * navigation/next-step creation, never a Proposal lifecycle change — no
 * approval, versioning, or Outlook/PDF workflow (Phase 4, out of scope).
 *
 * Eligibility (enforced in WorkflowTransitionService::transitionProposalContinuation()):
 * Won/Lost -> no ordinary continuation. Hold -> FollowUpRequired only.
 * Any other non-final state -> all three.
 */
enum ProposalContinuation: string implements HasColor, HasLabel
{
    case FollowUpRequired = 'follow_up_required';
    case DemoRequired = 'demo_required';
    case RequirementClarificationRequired = 'requirement_clarification_required';

    public function getLabel(): string
    {
        return match ($this) {
            self::FollowUpRequired => 'Follow-Up Required',
            self::DemoRequired => 'Demo Required',
            self::RequirementClarificationRequired => 'Requirement Clarification Required',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::FollowUpRequired => 'warning',
            self::DemoRequired => 'info',
            self::RequirementClarificationRequired => 'gray',
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
