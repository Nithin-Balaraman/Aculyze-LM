<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4A-3.1 (schema foundation only — recording behavior is the 4A-3.4
 * ProposalClientResponseService). One row per client response to a Sent
 * ProposalVersion (PHASE4_OUTCOME_CUTOVER_GATE item 1) — append-only,
 * never edited or deleted. The response targets the EXACT eligible Sent
 * Version the customer responded to, which does NOT have to equal the
 * Proposal's current_version_id (locked Decision 11).
 *
 * `operative_accepted_lock_key` is a STORED generated column — same
 * technique as proposal_versions.draft_lock_key and
 * proposal_pdf_artifacts.primary_lock_key — evaluating to `proposal_id`
 * only while `response_type='accepted' AND superseded_at IS NULL`, else
 * NULL. This enforces "at most one OPERATIVE Accepted response per
 * Proposal at a time", never "one Accepted ever" (locked Decision 13): a
 * future Reopen simply sets `superseded_at`/`superseded_by_response_id` on
 * the old Accepted row, freeing the lock for a new one, with no migration
 * required.
 *
 * `resulting_draft_version_id`/`follow_up_id` are real, restrictOnDelete
 * FKs (not the existing unenforced origin_type/origin_id lineage pattern
 * documented in docs/OPEN_BUSINESS_DECISIONS.md OPEN-2) — this is
 * permanent commercial/audit evidence and must never silently dangle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposal_client_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('proposal_id')->constrained('proposals')->restrictOnDelete();
            $table->foreignId('proposal_version_id')->constrained('proposal_versions')->restrictOnDelete();
            $table->string('response_type');
            $table->text('reason')->nullable();
            $table->text('notes')->nullable();
            $table->string('next_action')->nullable();
            $table->foreignId('resulting_draft_version_id')->nullable()->constrained('proposal_versions')->restrictOnDelete();
            $table->foreignId('follow_up_id')->nullable()->constrained('follow_ups')->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at');
            $table->string('idempotency_key');
            $table->timestamp('superseded_at')->nullable();
            $table->foreignId('superseded_by_response_id')->nullable()->constrained('proposal_client_responses')->nullOnDelete();
            $table->timestamps();

            $table->unsignedBigInteger('operative_accepted_lock_key')
                ->nullable()
                ->storedAs("case when response_type = 'accepted' and superseded_at is null then proposal_id else null end");

            $table->unique('idempotency_key');
            $table->unique('operative_accepted_lock_key');
            // Explicit short index names: the default composite index names
            // exceed MySQL/MariaDB's 64-character identifier limit, same
            // precedent as proposal_version_line_tax_components'.
            $table->index(['organization_id', 'proposal_id'], 'pcr_organization_id_proposal_id_index');
            $table->index(['organization_id', 'proposal_version_id'], 'pcr_organization_id_version_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_client_responses');
    }
};
