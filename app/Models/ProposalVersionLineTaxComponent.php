<?php

namespace App\Models;

use App\Enums\ProposalTaxComponentType;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\EnforcesSameOrganizationRelations;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4A-1: one tax component snapshot (CGST/SGST/IGST/Cess/Other) on a
 * ProposalVersionLine — see App\Enums\ProposalTaxComponentType's own
 * docblock for why a line supports one-or-more of these rather than a
 * single tax_id.
 */
class ProposalVersionLineTaxComponent extends Model
{
    use BelongsToOrganization, EnforcesSameOrganizationRelations, HasFactory;

    protected $fillable = [
        'proposal_version_line_id',
        'component_type',
        'rate',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'component_type' => ProposalTaxComponentType::class,
            'rate' => 'decimal:4',
            'amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new OrganizationScope);
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(ProposalVersionLine::class, 'proposal_version_line_id');
    }

    /** Inherits organization_id from the ProposalVersionLine this component belongs to. */
    protected function inheritedOrganizationId(): ?int
    {
        if (! $this->proposal_version_line_id) {
            return null;
        }

        return DB::table('proposal_version_lines')->where('id', $this->proposal_version_line_id)->value('organization_id');
    }

    /** @return array<string, array{0: string, 1: string}> */
    protected function organizationScopedRelations(): array
    {
        return [
            'proposal_version_line_id' => ['proposal_version_lines', 'Proposal Version Line'],
        ];
    }
}
