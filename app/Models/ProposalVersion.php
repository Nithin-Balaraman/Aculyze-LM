<?php

namespace App\Models;

use App\Enums\ProposalVersionLifecycle;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\EnforcesSameOrganizationRelations;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Phase 4A-1: one exact, immutable commercial-document snapshot of a
 * Proposal (Master BA Specification section 3.2). Only a Draft
 * (lifecycle_status Draft) is commercially editable; every later revision
 * (a later sub-phase) clones the prior version and its lines into
 * entirely new rows rather than mutating anything here.
 *
 * Supersession is metadata, not a lifecycle value — see
 * App\Enums\ProposalVersionLifecycle's own docblock.
 *
 * Every legacy-backfilled version (is_legacy_backfill = true) carries
 * only what App\Console\Commands\BackfillProposalVersions could prove from
 * the pre-Phase-4 Proposal row it came from — no fabricated approval
 * evidence, customer snapshot, line items, or tax breakup.
 */
class ProposalVersion extends Model
{
    use BelongsToOrganization, EnforcesSameOrganizationRelations, HasFactory;

    /**
     * Frozen from the moment a row is first persisted, regardless of
     * lifecycle_status — these describe what this row IS, never what it
     * currently contains, so nothing (not even a Draft-stage edit) may
     * ever change them (4A-2.1 locked Decision 11).
     *
     * @var array<int, string>
     */
    private const IMMUTABLE_IDENTITY_FIELDS = [
        'organization_id',
        'proposal_id',
        'version_number',
        'is_legacy_backfill',
    ];

    /**
     * Commercial/customer content — editable only while this row's
     * ORIGINAL (pre-save) lifecycle_status is still Draft. Workflow
     * metadata (lifecycle_status itself, supersession, and every actor/
     * timestamp/comment evidence field) is deliberately NOT in this list —
     * those are written by 4A-2.2's controlled services after this row
     * has already frozen (4A-2.1 locked Decision 11).
     *
     * @var array<int, string>
     */
    private const COMMERCIAL_CONTENT_FIELDS = [
        'customer_name_snapshot',
        'customer_gstin_snapshot',
        'billing_address_snapshot',
        'billing_state_snapshot',
        'place_of_supply_snapshot',
        'payment_terms',
        'validity_terms',
        'scope_notes',
        'subtotal',
        'total_discount',
        'tax_total',
        'grand_total',
        'currency_code',
    ];

    protected $fillable = [
        'proposal_id',
        'version_number',
        'lifecycle_status',
        'is_legacy_backfill',
        'superseded_at',
        'superseded_by_version_id',
        'customer_name_snapshot',
        'customer_gstin_snapshot',
        'billing_address_snapshot',
        'billing_state_snapshot',
        'place_of_supply_snapshot',
        'payment_terms',
        'validity_terms',
        'scope_notes',
        'subtotal',
        'total_discount',
        'tax_total',
        'grand_total',
        'currency_code',
        'submitted_by',
        'submitted_at',
        'manager_reviewed_by',
        'manager_reviewed_at',
        'manager_review_comment',
        'approved_by',
        'approved_at',
        'approval_comment',
        'returned_by',
        'returned_at',
        'return_reason',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'lifecycle_status' => ProposalVersionLifecycle::class,
            'is_legacy_backfill' => 'boolean',
            'superseded_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'total_discount' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'submitted_at' => 'datetime',
            'manager_reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'returned_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::saving(function (self $version): void {
            if (! $version->exists) {
                return;
            }

            foreach (self::IMMUTABLE_IDENTITY_FIELDS as $field) {
                if ($version->isDirty($field)) {
                    throw new LogicException(
                        "ProposalVersion #{$version->getKey()}'s {$field} is immutable once created and cannot be changed."
                    );
                }
            }

            $originalLifecycle = $version->getOriginal('lifecycle_status');

            // Only a row whose lifecycle was ALREADY non-Draft before this
            // save began is frozen — this deliberately still allows the one
            // legitimate Draft -> Submitted save (4A-2.2) to persist its
            // final recalculated commercial values in the same call that
            // moves lifecycle_status off Draft.
            if ($originalLifecycle instanceof ProposalVersionLifecycle && ! $originalLifecycle->isEditable()) {
                foreach (self::COMMERCIAL_CONTENT_FIELDS as $field) {
                    if ($version->isDirty($field)) {
                        throw new LogicException(
                            "ProposalVersion #{$version->getKey()} is no longer Draft ({$originalLifecycle->value}) — its commercial/customer content is frozen and {$field} cannot be changed."
                        );
                    }
                }
            }
        });
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ProposalVersionLine::class)->orderBy('line_number');
    }

    /** The newer version that superseded this one, if any. */
    public function supersededByVersion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_version_id');
    }

    /** The older version this one superseded, if any — reverse of supersededByVersion(). */
    public function supersedes(): HasOne
    {
        return $this->hasOne(self::class, 'superseded_by_version_id');
    }

    public function isEditable(): bool
    {
        return $this->lifecycle_status->isEditable();
    }

    /** Inherits organization_id from the Proposal this Version belongs to. */
    protected function inheritedOrganizationId(): ?int
    {
        if (! $this->proposal_id) {
            return null;
        }

        return DB::table('proposals')->where('id', $this->proposal_id)->value('organization_id');
    }

    /** @return array<string, array{0: string, 1: string}> */
    protected function organizationScopedRelations(): array
    {
        return [
            'proposal_id' => ['proposals', 'Proposal'],
            'superseded_by_version_id' => ['proposal_versions', 'superseding Version'],
            'submitted_by' => ['users', 'submitting User'],
            'manager_reviewed_by' => ['users', 'reviewing User'],
            'approved_by' => ['users', 'approving User'],
            'returned_by' => ['users', 'returning User'],
        ];
    }
}
