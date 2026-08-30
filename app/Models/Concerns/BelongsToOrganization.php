<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Deterministic organization_id assignment on create — deliberately NOT
 * "always derive from auth()->user()" (Phase 1 plan, "organization
 * assignment must not depend only on auth()"). Resolution order:
 *
 *   1. Already explicitly set on the model — never overridden.
 *   2. inheritedOrganizationId() — each model that has a natural parent
 *      (a Call-generated Follow-Up inherits from its Prospect, a
 *      Follow-Up-completion-generated Call Record inherits from that
 *      Follow-Up's own Prospect, etc.) overrides this to derive it
 *      deterministically, never from whoever happens to be logged in.
 *   3. TenantContext::current() — the ambient context, itself explicitly
 *      established by web middleware, Tenancy::runAs(), or a test — used
 *      only when there's genuinely no parent to inherit from (e.g. a
 *      brand-new Prospect).
 *   4. None of the above resolves — throw, rather than silently leaving
 *      organization_id null or guessing.
 *
 * Applied to every organization-owned model (Prospect, CallRecord,
 * FollowUp, Appointment, Lead, Proposal, ExportRequest). Deliberately NOT
 * applied to User — see App\Models\Scopes\OrganizationScope's docblock:
 * authentication must be able to look a user up by email before any
 * organization context exists, so User carries organization_id as a plain
 * column without the global scope, and every place User visibility
 * matters checks organization_id equality explicitly instead.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        // Registered on saving (fires before creating for a new model) —
        // not creating — deliberately, so App\Models\Concerns\
        // EnforcesSameOrganizationRelations (also registered on saving,
        // declared after this trait on every model that uses both) can
        // rely on organization_id already being resolved by the time its
        // own cross-organization reference check runs. Only ever acts on
        // a brand-new record — an existing record's organization_id is
        // never touched here.
        static::saving(function ($model): void {
            if ($model->exists) {
                return;
            }

            if ($model->organization_id !== null) {
                return;
            }

            $inherited = $model->inheritedOrganizationId();

            if ($inherited !== null) {
                $model->organization_id = $inherited;

                return;
            }

            $current = TenantContext::current();

            if ($current !== null) {
                $model->organization_id = $current;

                return;
            }

            throw new RuntimeException(
                static::class.' could not determine an organization_id when creating — no explicit '.
                'value was set, no parent relationship to inherit from, and no active TenantContext. '.
                'Refusing to create the record rather than leaving organization_id unset.'
            );
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Override on models that have a natural, deterministic parent to
     * derive organization_id from instead of falling back to ambient
     * TenantContext. Return null (the default) when there is none.
     */
    protected function inheritedOrganizationId(): ?int
    {
        return null;
    }
}
