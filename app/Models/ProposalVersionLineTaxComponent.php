<?php

namespace App\Models;

use App\Enums\ProposalTaxComponentType;
use App\Enums\ProposalVersionLifecycle;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\EnforcesSameOrganizationRelations;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use LogicException;

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

        // 4A-2.1 locked Decision 11 (E): a tax component may be created/
        // updated/deleted only while its parent line's ProposalVersion is
        // Draft. Model/domain-level, not UI-only — two-level lookup
        // (component -> line -> version), same direct-query idiom as
        // ProposalVersionLine's own guard.
        static::creating(function (self $component): void {
            self::assertParentVersionIsDraft($component->proposal_version_line_id, 'create');
        });

        static::updating(function (self $component): void {
            self::assertParentVersionIsDraft($component->proposal_version_line_id, 'update');

            if ($component->isDirty('proposal_version_line_id')) {
                self::assertParentVersionIsDraft($component->getOriginal('proposal_version_line_id'), 'update');
            }
        });

        static::deleting(function (self $component): void {
            self::assertParentVersionIsDraft($component->proposal_version_line_id, 'delete');
        });
    }

    private static function assertParentVersionIsDraft(?int $lineId, string $action): void
    {
        if (! $lineId) {
            return;
        }

        $versionId = DB::table('proposal_version_lines')->where('id', $lineId)->value('proposal_version_id');

        if (! $versionId) {
            return;
        }

        $lifecycle = DB::table('proposal_versions')->where('id', $versionId)->value('lifecycle_status');

        if ($lifecycle !== null && $lifecycle !== ProposalVersionLifecycle::Draft->value) {
            throw new LogicException(
                "Cannot {$action} a tax component on ProposalVersionLine #{$lineId}: its ProposalVersion #{$versionId} is no longer Draft ({$lifecycle})."
            );
        }
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
