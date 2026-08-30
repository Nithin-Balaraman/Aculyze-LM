<?php

namespace App\Support\Organization;

use App\Models\Organization;
use App\Support\Tenancy\TenantContext;

/**
 * Minimal read helper over organizations.settings (Phase 1 foundation only
 * — schema/plumbing, no per-module enforcement wiring yet; that remains
 * each module's own concern when it is actually touched in a later phase).
 * A JSON column on `organizations` rather than a dedicated table, since
 * there is exactly one organization today and the configuration surface is
 * still modest — see the create_organizations_table migration's docblock.
 *
 * No actual configuration values are set by Phase 1 (working hours,
 * reminder cadence, export thresholds, etc.) — those remain open
 * configuration decisions per the approved gap analysis, deliberately not
 * guessed here.
 */
class ModuleSettings
{
    /**
     * Every module defaults to enabled when unset, so a fresh organization
     * behaves exactly as if module configuration didn't exist yet — the
     * Phase 1 plan's own requirement that disabled-module widgets/actions
     * disappear cleanly (not the reverse: nothing should vanish merely
     * because settings.modules was never populated).
     */
    public static function enabled(string $module, ?int $organizationId = null): bool
    {
        $organizationId ??= TenantContext::current();

        if ($organizationId === null) {
            return true;
        }

        $settings = Organization::query()->find($organizationId)?->settings ?? [];

        return (bool) ($settings['modules'][$module] ?? true);
    }
}
