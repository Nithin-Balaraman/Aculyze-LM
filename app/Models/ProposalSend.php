<?php

namespace App\Models;

use App\Enums\ProposalSendMethod;
use App\Enums\ProposalSendStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\EnforcesSameOrganizationRelations;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4A-3.1 (schema/model only — the send service itself is 4A-3.3). One
 * send attempt against a ProposalVersion. Locked Decision 23: multiple
 * successful sends per Version are allowed, with no unique-per-Version
 * "sent_lock_key" of any kind — `idempotency_key` is the per-OPERATION
 * dedupe key only. Permanent, append-only — nothing here is ever updated
 * once created.
 */
class ProposalSend extends Model
{
    use BelongsToOrganization, EnforcesSameOrganizationRelations, HasFactory;

    protected $fillable = [
        'proposal_id',
        'proposal_version_id',
        'pdf_artifact_id',
        'method',
        'status',
        'to_recipients',
        'cc_recipients',
        'subject',
        'body',
        'notes',
        'attempted_at',
        'attempted_by',
        'sent_at',
        'sent_by',
        'provider_reference',
        'failure_reason',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'method' => ProposalSendMethod::class,
            'status' => ProposalSendStatus::class,
            'to_recipients' => 'array',
            'cc_recipients' => 'array',
            'attempted_at' => 'datetime',
            'sent_at' => 'datetime',
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

    public function pdfArtifact(): BelongsTo
    {
        return $this->belongsTo(ProposalPdfArtifact::class, 'pdf_artifact_id');
    }

    public function attemptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attempted_by');
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ProposalSendAttachment::class);
    }

    /** Inherits organization_id from the Proposal this send belongs to. */
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
            'pdf_artifact_id' => ['proposal_pdf_artifacts', 'PDF artifact'],
            'attempted_by' => ['users', 'attempting User'],
            'sent_by' => ['users', 'sending User'],
        ];
    }
}
