<?php

namespace App\Support\Authorization;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single place the Employee/Manager/Senior Manager visibility rule is
 * expressed (Phase 1 plan) — every model's scopeVisibleTo() and every
 * Policy's view/update checks call into this rather than each
 * reimplementing the tier branch, so the rule can't drift between the
 * eight models/policies that need it.
 *
 * Always composes INSIDE the organization boundary, never instead of it:
 * scopeFor() runs on a query that already carries
 * App\Models\Scopes\OrganizationScope, and canAccess() explicitly checks
 * organization equality itself (defense in depth — a record must never be
 * treated as accessible merely because it happened to reach this check,
 * in case a future call site ever fetches one outside the normal scoped
 * query path).
 */
class HierarchyVisibility
{
    /**
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    public static function scopeFor(Builder $query, User $actor, string $ownershipColumn): Builder
    {
        if ($actor->isSeniorManager()) {
            return $query;
        }

        if ($actor->isManager()) {
            $teamIds = User::query()
                ->where('manager_id', $actor->id)
                ->pluck('id')
                ->push($actor->id);

            return $query->whereIn($ownershipColumn, $teamIds);
        }

        return $query->where($ownershipColumn, $actor->id);
    }

    /**
     * @param  object{organization_id: int}  $record
     */
    public static function canAccess(User $actor, object $record, string $ownershipColumn): bool
    {
        if ($record->organization_id !== $actor->organization_id) {
            return false;
        }

        $ownerId = $record->{$ownershipColumn};

        if ($actor->isSeniorManager()) {
            return true;
        }

        if ($ownerId === $actor->id) {
            return true;
        }

        if ($actor->isManager() && $ownerId !== null) {
            return User::query()->where('id', $ownerId)->where('manager_id', $actor->id)->exists();
        }

        return false;
    }
}
