<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Phase 4A-3.1: the only two v1 next actions for an Other client response
 * (locked Decision 10) — deliberately narrow; Create Appointment, Create
 * Revision, Demo, and Requirement Clarification are excluded and remain in
 * their own first-class workflows, never folded into Other.
 */
enum ProposalClientResponseNextAction: string implements HasLabel
{
    case AwaitFurtherContact = 'await_further_contact';
    case CreateFollowUp = 'create_follow_up';

    public function getLabel(): string
    {
        return match ($this) {
            self::AwaitFurtherContact => 'Await Further Contact',
            self::CreateFollowUp => 'Create Follow-Up',
        };
    }
}
