<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Phase 2: what happened during a completed Demo — separate from
 * App\Enums\DemoNextAction (what was decided to happen next). See
 * App\Enums\DemoNextAction's own docblock for the full determinism table:
 * InterestedOk, CorrectionNeeded, and Other are non-deterministic (the
 * user must confirm/select next_action); every other outcome deterministically
 * implies exactly one DemoNextAction, enforced by
 * App\Services\WorkflowTransitionService::transitionDemoOutcome().
 */
enum DemoOutcome: string implements HasColor, HasLabel
{
    case InterestedOk = 'interested_ok';
    case CorrectionNeeded = 'correction_needed';
    case AnotherDemoRequired = 'another_demo_required';
    case MoreTimeDiscussion = 'more_time_discussion';
    case RequirementClarificationNeeded = 'requirement_clarification_needed';
    case ProposalRequired = 'proposal_required';
    case NotInterestedNoProgression = 'not_interested_no_progression';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::InterestedOk => 'Interested / OK',
            self::CorrectionNeeded => 'Correction Needed',
            self::AnotherDemoRequired => 'Another Demo Required',
            self::MoreTimeDiscussion => 'More Time / Discussion',
            self::RequirementClarificationNeeded => 'Requirement Clarification Needed',
            self::ProposalRequired => 'Proposal Required',
            self::NotInterestedNoProgression => 'Not Interested / No Progression',
            self::Other => 'Other',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::InterestedOk, self::ProposalRequired => 'success',
            self::CorrectionNeeded, self::AnotherDemoRequired, self::MoreTimeDiscussion, self::RequirementClarificationNeeded => 'warning',
            self::NotInterestedNoProgression => 'danger',
            self::Other => 'gray',
        };
    }

    /** Non-deterministic outcomes require the user to select next_action explicitly. */
    public function isNextActionDeterministic(): bool
    {
        return ! in_array($this, [self::InterestedOk, self::CorrectionNeeded, self::Other], true);
    }

    /**
     * The single, fixed DemoNextAction implied by a deterministic outcome.
     * Must only be called when isNextActionDeterministic() is true.
     */
    public function deterministicNextAction(): DemoNextAction
    {
        return match ($this) {
            self::AnotherDemoRequired => DemoNextAction::ScheduleAnotherDemo,
            self::MoreTimeDiscussion => DemoNextAction::CreateFollowUp,
            self::RequirementClarificationNeeded => DemoNextAction::ReturnToLeadForClarification,
            self::ProposalRequired => DemoNextAction::StartProposal,
            self::NotInterestedNoProgression => DemoNextAction::NoFurtherAction,
            default => throw new \LogicException("{$this->value} does not have a deterministic next action."),
        };
    }

    /**
     * The set of DemoNextAction values a user may choose from for a
     * non-deterministic outcome. Must only be called when
     * isNextActionDeterministic() is false.
     *
     * @return array<int, DemoNextAction>
     */
    public function allowedNextActions(): array
    {
        return match ($this) {
            self::InterestedOk, self::CorrectionNeeded => [
                DemoNextAction::StartProposal,
                DemoNextAction::ScheduleAnotherDemo,
                DemoNextAction::CreateFollowUp,
                DemoNextAction::ReturnToLeadForClarification,
            ],
            self::Other => [
                DemoNextAction::ScheduleAnotherDemo,
                DemoNextAction::ReturnToLeadForClarification,
                DemoNextAction::CreateFollowUp,
                DemoNextAction::StartProposal,
                DemoNextAction::NoFurtherAction,
            ],
            default => throw new \LogicException("{$this->value} does not have a user-selectable next action set."),
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
