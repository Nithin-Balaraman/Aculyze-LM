<?php

namespace App\Support\Tenancy;

use RuntimeException;

/**
 * Thrown by App\Models\Scopes\OrganizationScope when an organization-owned
 * model is queried with no TenantContext established. This is the
 * "fail closed" behavior required by the Phase 1 plan: absence of context
 * must never mean "skip the organization filter and return everything" —
 * it must stop the query outright. The only legitimate way to query these
 * models without an ambient context is through
 * App\Support\Tenancy\Tenancy::withoutScopeForSystemTask(), which removes
 * the scope entirely for its callback's duration rather than leaving it
 * attached-but-toothless.
 */
class TenantContextMissingException extends RuntimeException
{
    public static function forModel(string $modelClass): self
    {
        return new self(
            "Refusing to query [{$modelClass}] without an active TenantContext. ".
            'Organization isolation fails closed by design — set a tenant context '.
            '(e.g. via an authenticated request, or App\\Support\\Tenancy\\Tenancy::runAs()) '.
            'or use Tenancy::withoutScopeForSystemTask() for a deliberate, audited, cross-organization operation.'
        );
    }
}
