<?php

namespace App\Models;

use App\Enums\ProposalPdfArtifactStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\EnforcesSameOrganizationRelations;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4A-3.1 (schema/model only — generation itself is 4A-3.2). One PDF
 * generation attempt against a ProposalVersion, Success or Failed,
 * permanent and append-only — nothing here is ever updated once created
 * except the supersession pair (`superseded_at`/`superseded_by_artifact_id`)
 * written on the OLD primary the moment a correction produces a new one.
 * `primary_lock_key` (see the creating migration) is DB-computed and never
 * set directly.
 */
class ProposalPdfArtifact extends Model
{
    use BelongsToOrganization, EnforcesSameOrganizationRelations, HasFactory;

    protected $fillable = [
        'proposal_version_id',
        'status',
        'template_version',
        'checksum_sha256',
        'storage_path',
        'byte_size',
        'generated_at',
        'generated_by',
        'failure_reason',
        'correction_reason',
        'superseded_at',
        'superseded_by_artifact_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProposalPdfArtifactStatus::class,
            'byte_size' => 'integer',
            'generated_at' => 'datetime',
            'superseded_at' => 'datetime',
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

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /** The newer artifact that superseded this one, if any. */
    public function supersededByArtifact(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_artifact_id');
    }

    /** The older artifact this one superseded, if any — reverse of supersededByArtifact(). */
    public function supersedes(): HasOne
    {
        return $this->hasOne(self::class, 'superseded_by_artifact_id');
    }

    public function sends(): HasMany
    {
        return $this->hasMany(ProposalSend::class, 'pdf_artifact_id');
    }

    /** Inherits organization_id from the ProposalVersion this artifact belongs to. */
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
            'proposal_version_id' => ['proposal_versions', 'ProposalVersion'],
            'generated_by' => ['users', 'generating User'],
            'superseded_by_artifact_id' => ['proposal_pdf_artifacts', 'superseding artifact'],
        ];
    }
}
