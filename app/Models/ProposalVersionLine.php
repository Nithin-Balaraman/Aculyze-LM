<?php

namespace App\Models;

use App\Enums\ProposalLineDiscountType;
use App\Enums\ProposalVersionLifecycle;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\EnforcesSameOrganizationRelations;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use LogicException;

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

        // 4A-2.1 locked Decision 11 (D): a line may be created/updated/
        // deleted only while its parent ProposalVersion is Draft. Model/
        // domain-level, not UI-only — checked via a direct query against
        // the parent's CURRENT lifecycle_status, mirroring the existing
        // inheritedOrganizationId() idiom already used in this class,
        // rather than trusting a possibly-stale loaded relationship.
        static::creating(function (self $line): void {
            self::assertParentVersionIsDraft($line->proposal_version_id, 'create');
        });

        static::updating(function (self $line): void {
            self::assertParentVersionIsDraft($line->proposal_version_id, 'update');

            // Defensive: also reject moving a line AWAY from an already-frozen
            // parent onto a different one, not just editing it in place.
            if ($line->isDirty('proposal_version_id')) {
                self::assertParentVersionIsDraft($line->getOriginal('proposal_version_id'), 'update');
            }
        });

        static::deleting(function (self $line): void {
            self::assertParentVersionIsDraft($line->proposal_version_id, 'delete');
        });
    }

    private static function assertParentVersionIsDraft(?int $proposalVersionId, string $action): void
    {
        if (! $proposalVersionId) {
            return;
        }

        $lifecycle = DB::table('proposal_versions')->where('id', $proposalVersionId)->value('lifecycle_status');

        if ($lifecycle !== null && $lifecycle !== ProposalVersionLifecycle::Draft->value) {
            throw new LogicException(
                "Cannot {$action} a line on ProposalVersion #{$proposalVersionId}: it is no longer Draft ({$lifecycle})."
            );
        }
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
