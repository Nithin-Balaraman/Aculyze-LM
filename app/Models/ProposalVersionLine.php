<?php

namespace App\Models;

use App\Enums\ProposalLineDiscountType;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\EnforcesSameOrganizationRelations;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4A-1: one commercial line row snapshot, belonging to exactly one
 * ProposalVersion (never directly to a Proposal — see
 * App\Models\ProposalVersion's own docblock and Master BA Specification
 * section 3.3). No product/service reference: this repository has no
 * Product catalog model, so item_name/description are always free-text
 * snapshots.
 */
class ProposalVersionLine extends Model
{
    use BelongsToOrganization, EnforcesSameOrganizationRelations, HasFactory;

    protected $fillable = [
        'proposal_version_id',
        'line_number',
        'item_name',
        'description',
        'hsn_sac',
        'quantity',
        'unit',
        'unit_price',
        'discount_type',
        'discount_value',
        'discount_amount',
        'gross_amount',
        'taxable_amount',
        'tax_amount',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'discount_type' => ProposalLineDiscountType::class,
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:2',
            'discount_value' => 'decimal:4',
            'discount_amount' => 'decimal:2',
            'gross_amount' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new OrganizationScope);
    }

    public function proposalVersion(): BelongsTo
    {
        return $this->belongsTo(ProposalVersion::class);
    }

    public function taxComponents(): HasMany
    {
        return $this->hasMany(ProposalVersionLineTaxComponent::class);
    }

    /** Inherits organization_id from the ProposalVersion this line belongs to. */
    protected function inheritedOrganizationId(): ?int
    {
        if (! $this->proposal_version_id) {
            return null;
        }

        return DB::table('proposal_versions')->where('id', $this->proposal_version_id)->value('organization_id');
    }

    /** @return array<string, array{0: string, 1: string}> */
    protected function organizationScopedRelations(): array
    {
        return [
            'proposal_version_id' => ['proposal_versions', 'Proposal Version'],
        ];
    }
}
