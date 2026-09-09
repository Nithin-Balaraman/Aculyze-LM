<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4A-3.1 (schema foundation only — the send service itself is
 * 4A-3.3): one row per send attempt against a ProposalVersion. Locked
 * Decision 23 is authoritative: multiple SUCCESSFUL sends per Version are
 * allowed — there is deliberately NO unique-per-Version "sent_lock_key" of
 * any kind. `proposal_versions.sent_at` is written by the 4A-3.3 service to
 * the FIRST successful send only; every row here (first and later) carries
 * its own independent `sent_at`.
 *
 * `idempotency_key` is UNIQUE and is the per-OPERATION dedupe key (a
 * retried/double-submitted click reuses the same key and is a no-op; a
 * genuine later resend always mints a fresh one) — never a per-Version
 * lock.
 *
 * `proposal_id` is denormalized from `proposal_version_id` purely to avoid
 * joining through Version for every Proposal-scoped send list — the same
 * reasoning `proposals.prospect_id`'s own denormalization already
 * documents.
 *
 * Every FK is restrictOnDelete: this is permanent commercial/audit history
 * and no delete path exists anywhere for a ProposalSend, a
 * ProposalPdfArtifact, or a User with recorded actor history (see
 * EmployeeDeletionService::assertNotAProposalVersionActor()'s existing
 * precedent for submitted_by/approved_by/returned_by, which this follows
 * exactly for attempted_by/sent_by).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposal_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('proposal_id')->constrained('proposals')->restrictOnDelete();
            $table->foreignId('proposal_version_id')->constrained('proposal_versions')->restrictOnDelete();
            $table->foreignId('pdf_artifact_id')->constrained('proposal_pdf_artifacts')->restrictOnDelete();
            $table->string('method');
            $table->string('status');
            $table->json('to_recipients');
            $table->json('cc_recipients')->nullable();
            $table->string('subject')->nullable();
            $table->text('body')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('attempted_at');
            $table->foreignId('attempted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('provider_reference')->nullable();
            $table->text('failure_reason')->nullable();
            $table->string('idempotency_key');
            $table->timestamps();

            $table->unique('idempotency_key');
            $table->index(['organization_id', 'proposal_id'], 'proposal_sends_organization_id_proposal_id_index');
            // Explicit short index name: the default composite index name
            // exceeds MySQL/MariaDB's 64-character identifier limit, same
            // precedent as proposal_version_line_tax_components'.
            $table->index(['organization_id', 'proposal_version_id'], 'ps_organization_id_version_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_sends');
    }
};
