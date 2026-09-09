<?php

namespace App\Models;

use App\Enums\ProposalClientResponseNextAction;
use App\Enums\ProposalClientResponseType;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\EnforcesSameOrganizationRelations;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4A-3.1 (schema/model only — recording behavior is the 4A-3.4
 * ProposalClientResponseService). One client response to a Sent
 * ProposalVersion (PHASE4_OUTCOME_CUTOVER_GATE item 1) — append-only,
 * permanent. The response targets the EXACT eligible Sent Version the
 * customer responded to, which does NOT have to equal the Proposal's
 * current_version_id (locked Decision 11).
 *
 * `operative_accepted_lock_key` (see the creating migration) is DB-computed
 * and never set directly — it enforces "at most one OPERATIVE Accepted
 * response per Proposal at a time", never "one Accepted ever" (locked
 * Decision 13): a future Reopen sets `superseded_at`/
 * `superseded_by_response_id` on the old Accepted row, freeing the lock.
 */
class ProposalClientResponse extends Model
{
    use BelongsToOrganization, EnforcesSameOrganizationRelations, HasFactory;

    protected $fillable = [
        'proposal_id',
        'proposal_version_id',
        'response_type',
        'reason',
        'notes',
        'next_action',
        'resulting_draft_version_id',
        'follow_up_id',
        'recorded_by',
        'recorded_at',
        'idempotency_key',
        'superseded_at',
        'superseded_by_response_id',
    ];

    protected function casts(): array
    {
        return [
            'response_type' => ProposalClientResponseType::class,
            'next_action' => ProposalClientResponseNextAction::class,
            'recorded_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new OrganizationScope);
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    public function proposalVersion(): BelongsTo
    {
        return $this->belongsTo(ProposalVersion::class);
    }

    /** The new Draft this RevisionRequested response resolved to (created fresh, or the already-existing current Draft it was linked to). */
    public function resultingDraftVersion(): BelongsTo
    {
        return $this->belongsTo(ProposalVersion::class, 'resulting_draft_version_id');
    }

    public function followUp(): BelongsTo
    {
        return $this->belongsTo(FollowUp::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** The newer response that superseded this one, if any (future Reopen). */
    public function supersededByResponse(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_response_id');
    }

    /** The older response this one superseded, if any — reverse of supersededByResponse(). */
    public function supersedes(): HasOne
    {
        return $this->hasOne(self::class, 'superseded_by_response_id');
    }

    public function billingHandoff(): HasOne
    {
        return $this->hasOne(ProposalBillingHandoff::class, 'accepted_response_id');
    }

    /** Inherits organization_id from the Proposal this response belongs to. */
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
            'proposal_version_id' => ['proposal_versions', 'ProposalVersion'],
            'resulting_draft_version_id' => ['proposal_versions', 'resulting Draft Version'],
            'follow_up_id' => ['follow_ups', 'Follow-Up'],
            'recorded_by' => ['users', 'recording User'],
            'superseded_by_response_id' => ['proposal_client_responses', 'superseding response'],
        ];
    }
}
