<?php

namespace App\Support\Organization;

use App\Models\Organization;
use LogicException;

/**
 * Phase 4A-3.2 (locked Decision 2): resolves the legal identity/letterhead
 * an organization's final Proposal PDF is rendered with. Read order:
 *
 *   1. `organizations.settings['identity']` (per-organization override —
 *      no identity-management UI exists in 4A-3, so this is only ever set
 *      directly in the database for now, same as
 *      App\Services\ProposalNumberService's own `proposal_number_prefix`
 *      key).
 *   2. `config('aculyze.organization_identity')` (fallback).
 *
 * Mandatory fields (legal_name, registered_address, gstin) must be present
 * from EITHER source, mixed field-by-field — an organization may set only
 * `gstin` in its own settings and still fall back to config for the rest.
 * Optional fields (phone/email/website/logo_path) default to null, never
 * fabricated.
 *
 * Never introduces a separate identity snapshot table — the stored PDF
 * bytes + checksum are the historical truth (locked Decision 2); a future
 * rendering correction may legitimately use updated identity while the
 * prior artifact remains permanently preserved exactly as it was rendered.
 */
class OrganizationIdentityResolver
{
    private const MANDATORY_FIELDS = ['legal_name', 'registered_address', 'gstin'];

    private const OPTIONAL_FIELDS = ['phone', 'email', 'website', 'logo_path'];

    /**
     * @return array{legal_name: string, registered_address: string, gstin: string, phone: ?string, email: ?string, website: ?string, logo_path: ?string}
     *
     * @throws LogicException If any mandatory field is unavailable from both the organization's own
     *                        settings and the config fallback.
     */
    public function resolveOrFail(int $organizationId): array
    {
        $fromOrganization = Organization::query()->find($organizationId)?->settings['identity'] ?? [];
        $fromConfig = (array) config('aculyze.organization_identity', []);

        $resolved = [];
        $missing = [];

        foreach (self::MANDATORY_FIELDS as $field) {
            $value = $this->firstNonBlank($fromOrganization[$field] ?? null, $fromConfig[$field] ?? null);

            if ($value === null) {
                $missing[] = $field;
            }

            $resolved[$field] = $value;
        }

        if ($missing !== []) {
            throw new LogicException(
                'Organization #'.$organizationId.' is missing mandatory legal identity field(s) required before a final Proposal PDF can be generated: '.
                implode(', ', $missing).
                '. Set organizations.settings[\'identity\'] or the matching config/aculyze.php fallback, then retry.'
            );
        }

        foreach (self::OPTIONAL_FIELDS as $field) {
            $resolved[$field] = $this->firstNonBlank($fromOrganization[$field] ?? null, $fromConfig[$field] ?? null);
        }

        /** @var array{legal_name: string, registered_address: string, gstin: string, phone: ?string, email: ?string, website: ?string, logo_path: ?string} $resolved */
        return $resolved;
    }

    private function firstNonBlank(mixed $primary, mixed $fallback): ?string
    {
        if (is_string($primary) && trim($primary) !== '') {
            return $primary;
        }

        if (is_string($fallback) && trim($fallback) !== '') {
            return $fallback;
        }

        return null;
    }
}
