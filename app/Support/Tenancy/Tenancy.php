<?php

namespace App\Support\Tenancy;

use App\Support\Audit\AuditLogger;
use Closure;

/**
 * The only approved place to intentionally change or bypass tenant scoping.
 * Application code elsewhere must never call TenantContext::set()/forget()
 * directly, and must never call withoutGlobalScope(OrganizationScope::class)
 * (or withoutGlobalScopes()), or read/write isBypassing()'s underlying
 * state, directly either — see tests/Feature/TenancyBypassUsageTest.php,
 * which fails the suite if any file outside this one does. This is a
 * documented coding invariant enforced by that test and by code review, not
 * a runtime guarantee PHP itself provides — nothing stops another file from
 * calling Eloquent's own withoutGlobalScope() directly; the test is what
 * catches it.
 */
class Tenancy
{
    /**
     * Set only by withoutScopeForSystemTask() below — App\Models\Scopes\
     * OrganizationScope checks this before applying its filter.
     */
    private static bool $bypassing = false;

    /**
     * Run $callback with TenantContext temporarily set to $organizationId,
     * restoring whatever context existed before — even if $callback throws
     * — via try/finally. Correct for arbitrary nesting depth: each call
     * captures its own "previous" value on the PHP call stack, so an inner
     * runAs() restores the middle context before the outer runAs() restores
     * whatever came before that.
     */
    public static function runAs(int $organizationId, Closure $callback): mixed
    {
        $previous = TenantContext::current();
        TenantContext::set($organizationId);

        try {
            return $callback();
        } finally {
            TenantContext::set($previous);
        }
    }

    /**
     * The explicit, audited, full bypass for genuinely cross-organization/
     * system-level work (e.g. the one-time organization backfill, or a
     * future cross-org admin report) — App\Models\Scopes\OrganizationScope
     * applies no filter at all for the duration of $callback, regardless of
     * TenantContext. Always writes exactly one audit_events row (scope =
     * 'system', organization_id = null) before running $callback, so every
     * use of this method is auditable by construction, not by convention —
     * see tests covering "legitimate system bypass creates the required
     * audit event."
     *
     * Restores the previous bypass state via try/finally, correctly nesting
     * the same way runAs() does.
     */
    public static function withoutScopeForSystemTask(string $reason, Closure $callback): mixed
    {
        AuditLogger::record(
            entityType: 'system',
            entityId: null,
            action: 'tenant_scope_bypassed',
            organizationId: null,
            description: $reason,
        );

        $previous = self::$bypassing;
        self::$bypassing = true;

        try {
            return $callback();
        } finally {
            self::$bypassing = $previous;
        }
    }

    /** Read by App\Models\Scopes\OrganizationScope only. */
    public static function isBypassing(): bool
    {
        return self::$bypassing;
    }
}
