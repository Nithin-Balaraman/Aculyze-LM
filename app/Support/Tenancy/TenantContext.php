<?php

namespace App\Support\Tenancy;

/**
 * Holds "which organization is the current operation scoped to" for the
 * lifetime of a request/job/command. This is deliberately NOT derived by
 * App\Models\Scopes\OrganizationScope calling auth()->user() directly —
 * every call site (web middleware, queued jobs, CLI commands, tests)
 * establishes it explicitly, so the same scope class behaves correctly
 * everywhere (Phase 1 plan, "Organization assignment must not depend only
 * on auth()").
 *
 * Never mutate $organizationId directly from outside this class — always
 * go through set()/forget()/Tenancy::runAs() so restoration semantics
 * (Tenancy::runAs()'s try/finally) stay correct.
 */
class TenantContext
{
    private static ?int $organizationId = null;

    public static function current(): ?int
    {
        return static::$organizationId;
    }

    public static function set(?int $organizationId): void
    {
        static::$organizationId = $organizationId;
    }

    public static function forget(): void
    {
        static::$organizationId = null;
    }

    public static function hasContext(): bool
    {
        return static::$organizationId !== null;
    }
}
