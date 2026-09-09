<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\EnforcesSameOrganizationRelations;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 4A-3.1 (locked Decision 1): the per-organization, per-year counter
 * behind App\Services\ProposalNumberService — never read/written outside
 * that service. `organization_id` is always set explicitly by the service
 * (never inferred), so BelongsToOrganization's TenantContext fallback is
 * never actually exercised here; it is applied purely for the same
 * fail-closed consistency every organization-owned table in this schema
 * carries.
 */
class ProposalNumberSequence extends Model
{
    use BelongsToOrganization, EnforcesSameOrganizationRelations, HasFactory;

    protected $fillable = [
        'organization_id',
        'year',
        'next_number',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'next_number' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new OrganizationScope);
    }

    /** @return array<string, array{0: string, 1: string}> */
    protected function organizationScopedRelations(): array
    {
        return [];
    }
}
