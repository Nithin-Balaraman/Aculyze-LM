<?php

namespace App\Models;

use App\Enums\ProposalBillingHandoffStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\EnforcesSameOrganizationRelations;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4A-3.1 (schema/model only — populated by the 4A-3.4 Accepted path).
 * One provider-independent Pending handoff per Accepted event (locked
 * Decisions 13/15). No external billing API call exists in 4A-3.
 *
 * The exactly-once backstop is `UNIQUE(accepted_response_id)`, not
 * `proposal_id`/`winning_version_id` — see the creating migration's own
 * docblock for the full reasoning (a future Reopen must be able to produce
 * a second legitimate handoff for a second Accepted event on the same
 * Proposal).
 */
class ProposalBillingHandoff extends Model
{
    use BelongsToOrganization, EnforcesSameOrganizationRelations, HasFactory;

    protected $fillable = [
        'proposal_id',
        'winning_version_id',
        'accepted_response_id',
        'idempotency_key',
        'status',
        'attempts',
        'last_attempt_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProposalBillingHandoffStatus::class,
            'attempts' => 'integer',
            'last_attempt_at' => 'datetime',
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

    public function winningVersion(): BelongsTo
    {
        return $this->belongsTo(ProposalVersion::class, 'winning_version_id');
    }

    public function acceptedResponse(): BelongsTo
    {
        return $this->belongsTo(ProposalClientResponse::class, 'accepted_response_id');
    }

    /** Inherits organization_id from the Proposal this handoff belongs to. */
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
            'winning_version_id' => ['proposal_versions', 'winning Version'],
            'accepted_response_id' => ['proposal_client_responses', 'Accepted response'],
        ];
    }
}
