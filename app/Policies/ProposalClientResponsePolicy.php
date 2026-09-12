<?php

namespace App\Policies;

use App\Models\Proposal;
use App\Models\User;
use App\Support\Authorization\HierarchyVisibility;

/**
 * Phase 4A-3.4, Section C: authorization for recording an exact customer
 * response against a Sent ProposalVersion. Unlike ProposalReleasePolicy/
 * ProposalSendPolicy (each narrower than full hierarchy scope), ALL three
 * tiers may record ANY of the five response types — the assigned Employee,
 * a Manager within hierarchy, or a Senior Manager organization-wide.
 * Recording the customer FACT is deliberately separate from generic
 * commercial-edit authority (e.g. standalone createRevision() stays
 * Manager-or-above only) — this policy is the sole gate for the client-
 * response workflow, never reused to authorize anything else.
 */
class ProposalClientResponsePolicy
{
    public function recordClientResponse(User $user, Proposal $proposal): bool
    {
        return HierarchyVisibility::canAccess($user, $proposal, 'assigned_to');
    }
}
