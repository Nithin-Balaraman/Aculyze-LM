<?php

namespace App\Policies;

use App\Models\ProposalVersion;
use App\Models\User;
use App\Support\Authorization\HierarchyVisibility;

/**
 * Phase 4A-3.3, Section P: authorization for recording a Manual Send and
 * for viewing send history.
 *
 * send() is deliberately the assigned Employee ONLY — not hierarchy-wide
 * like every other Proposal-commercial policy in this codebase. Recording
 * a manual send asserts that a real, physical send already happened
 * outside Aculyze-LM, so only the actual assigned staff member (who
 * presumably performed or witnessed it) may assert it; the kickoff
 * explicitly warns against inventing broader Employee-like send authority
 * for Manager/Senior Manager without an explicit locked design decision
 * saying so, and none exists.
 *
 * viewHistory() is the normal hierarchy-wide read visibility (Employee
 * viewing their own, Manager within hierarchy, Senior Manager
 * organization-wide) — Section Q/P: Manager/Senior Manager retain send
 * history visibility even though they cannot record one themselves.
 */
class ProposalSendPolicy
{
    public function send(User $user, ProposalVersion $version): bool
    {
        $proposal = $version->proposal;

        return $proposal->organization_id === $user->organization_id
            && $proposal->assigned_to === $user->id;
    }

    public function viewHistory(User $user, ProposalVersion $version): bool
    {
        return HierarchyVisibility::canAccess($user, $version->proposal, 'assigned_to');
    }
}
