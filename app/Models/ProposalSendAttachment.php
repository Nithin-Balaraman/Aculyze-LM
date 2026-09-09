<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\EnforcesSameOrganizationRelations;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4A-3.1 (schema/model only — populated by the 4A-3.3 send service).
 * One row per Proposal attachment selected into a send's immutable manifest
 * (locked Decision 24). Deliberately no uniqueness on `checksum_sha256` —
 * two selected attachments with identical bytes but different original
 * filenames are two distinct rows; byte-level deduplication happens at the
 * storage-write layer, never here. Immutable — created once, never
 * updated (no `updated_at` column at all).
 */
class ProposalSendAttachment extends Model
{
    use BelongsToOrganization, EnforcesSameOrganizationRelations, HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'proposal_send_id',
        'checksum_sha256',
        'archived_path',
        'original_filename',
        'mime_type',
        'byte_size',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'byte_size' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::creating(function (self $attachment): void {
            $attachment->created_at ??= now();
        });
    }

    public function proposalSend(): BelongsTo
    {
        return $this->belongsTo(ProposalSend::class);
    }

    /** Inherits organization_id from the ProposalSend this attachment belongs to. */
    protected function inheritedOrganizationId(): ?int
    {
        if (! $this->proposal_send_id) {
            return null;
        }

        return DB::table('proposal_sends')->where('id', $this->proposal_send_id)->value('organization_id');
    }

    /** @return array<string, array{0: string, 1: string}> */
    protected function organizationScopedRelations(): array
    {
        return [
            'proposal_send_id' => ['proposal_sends', 'ProposalSend'],
        ];
    }
}
