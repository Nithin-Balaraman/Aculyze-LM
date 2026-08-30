<?php

namespace App\Models\Scopes;

use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantContextMissingException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * The organization/tenant security boundary, applied as a default global
 * scope rather than left as something every developer must remember to
 * add per-query (Phase 1 plan, "tenant isolation must be a system
 * boundary"). Because Eloquent applies global scopes beneath direct
 * queries, relationship loads, and every Filament Resource's
 * getEloquentQuery(), attaching this once per model protects all of those
 * surfaces uniformly.
 *
 * FAILS CLOSED: if no TenantContext is set, this throws rather than
 * silently omitting the WHERE clause (which would return every
 * organization's data) or silently returning nothing (which could be
 * mistaken for "correctly, there's just no data"). The only way to
 * legitimately query without context is App\Support\Tenancy\Tenancy::
 * withoutScopeForSystemTask(), which removes this scope for its
 * callback's duration — a distinct, explicit, audited code path, never a
 * side effect of context merely being absent.
 *
 * Attached to every organization-owned model: Prospect, CallRecord,
 * FollowUp, Appointment, Lead, Proposal, ExportRequest, AuditEvent.
 * Deliberately NOT attached to User (see App\Models\Concerns\
 * BelongsToOrganization's docblock) or Organization itself (it IS the
 * boundary).
 */
class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (Tenancy::isBypassing()) {
            return;
        }

        $organizationId = TenantContext::current();

        if ($organizationId === null) {
            throw TenantContextMissingException::forModel($model::class);
        }

        $builder->where($model->qualifyColumn('organization_id'), $organizationId);
    }
}
