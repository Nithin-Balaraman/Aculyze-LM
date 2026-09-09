<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4A-3.1 (schema foundation only — generation itself is 4A-3.2): one
 * row per PDF generation attempt against a ProposalVersion, Success or
 * Failed, permanent and append-only. Never populated for a legacy
 * backfilled Version (enforced at the policy/service layer in 4A-3.2, not
 * here — no fabricated artifact history).
 *
 * `primary_lock_key` is a STORED generated column, evaluating to
 * `proposal_version_id` only while `status='success' AND superseded_at IS
 * NULL`, else NULL — the exact same technique already proven by
 * proposal_versions.draft_lock_key: MariaDB/MySQL treat every NULL in a
 * UNIQUE index as distinct, so any number of Failed/superseded rows coexist
 * freely, while only one row per Version may ever claim the "current
 * primary" slot. This is the concurrency backstop, not the primary
 * mechanism — the 4A-3.2 service must lockForUpdate() the ProposalVersion
 * row and, when correcting, UPDATE the old primary's superseded_at BEFORE
 * INSERTing the new one (update-then-insert order matters under this
 * constraint).
 *
 * FKs are restrictOnDelete throughout except the self-referencing
 * `superseded_by_artifact_id` (nullOnDelete, mirroring
 * proposal_versions.superseded_by_version_id) — this is permanent
 * commercial/audit evidence and no delete path exists for it anywhere in
 * the application.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposal_pdf_artifacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('proposal_version_id')->constrained('proposal_versions')->restrictOnDelete();
            $table->string('status');
            $table->string('template_version');
            $table->char('checksum_sha256', 64)->nullable();
            $table->string('storage_path')->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->timestamp('generated_at');
            $table->foreignId('generated_by')->constrained('users')->restrictOnDelete();
            $table->text('failure_reason')->nullable();
            $table->text('correction_reason')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->foreignId('superseded_by_artifact_id')->nullable()->constrained('proposal_pdf_artifacts')->nullOnDelete();
            $table->timestamps();

            $table->unsignedBigInteger('primary_lock_key')
                ->nullable()
                ->storedAs("case when status = 'success' and superseded_at is null then proposal_version_id else null end");

            $table->unique('primary_lock_key');
            // Explicit short index name: the default composite index name
            // is at MySQL/MariaDB's 64-character identifier limit, same
            // precedent as proposal_version_line_tax_components'.
            $table->index(['organization_id', 'proposal_version_id'], 'ppa_organization_id_version_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_pdf_artifacts');
    }
};
