<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Phase 2: what the salesperson decided should happen next after a Demo —
 * distinct from App\Enums\DemoOutcome (what happened). Kept as its own
 * persisted column rather than derived purely from outcome, because some
 * outcomes (Interested/OK, Correction Needed, Other) permit more than one
 * valid destination — see DemoOutcome::isNextActionDeterministic()/
 * deterministicNextAction()/allowedNextActions() for the full mapping this
 * enum's values are validated against in
 * App\Services\WorkflowTransitionService::transitionDemoOutcome().
 */
enum DemoNextAction: string implements HasColor, HasLabel
{
    case StartProposal = 'start_proposal';
    case ScheduleAnotherDemo = 'schedule_another_demo';
    case CreateFollowUp = 'create_follow_up';
    case ReturnToLeadForClarification = 'return_to_lead_for_clarification';
    case NoFurtherAction = 'no_further_action';

    public function getLabel(): string
    {
        return match ($this) {
            self::StartProposal => 'Start Proposal',
            self::ScheduleAnotherDemo => 'Schedule Another Demo',
            self::CreateFollowUp => 'Create Follow-Up',
            self::ReturnToLeadForClarification => 'Return to Lead for Clarification',
            self::NoFurtherAction => 'No Further Action',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::StartProposal => 'success',
            self::ScheduleAnotherDemo, self::CreateFollowUp, self::ReturnToLeadForClarification => 'info',
            self::NoFurtherAction => 'gray',
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
