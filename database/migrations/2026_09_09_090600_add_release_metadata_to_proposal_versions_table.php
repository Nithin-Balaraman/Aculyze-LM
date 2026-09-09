<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4A-3.1 (schema foundation only — the Release service itself is
 * 4A-3.3). Release is metadata layered on top of the existing lifecycle,
 * never a new lifecycle value of its own (locked Decision 4 family,
 * mirroring ProposalVersionLifecycle's own "supersession is metadata, not a
 * lifecycle value" precedent) — no new column is added to
 * ProposalVersionLifecycle for this.
 *
 * Release staleness is DERIVED, never stored: a Release is stale exactly
 * when `released_pdf_artifact_id` no longer equals the Version's current
 * primary successful artifact (`proposal_pdf_artifacts.primary_lock_key`).
 * No redundant boolean is added here.
 *
 * `released_pdf_artifact_id` is restrictOnDelete, not nullOnDelete — a
 * released Version must never silently lose which artifact it released.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposal_versions', function (Blueprint $table) {
            $table->timestamp('released_at')->nullable()->after('sent_at');
            $table->foreignId('released_by')->nullable()->after('released_at')->constrained('users')->restrictOnDelete();
            $table->foreignId('released_pdf_artifact_id')->nullable()->after('released_by')->constrained('proposal_pdf_artifacts')->restrictOnDelete();
            $table->text('release_comment')->nullable()->after('released_pdf_artifact_id');
        });
    }

    public function down(): void
    {
        Schema::table('proposal_versions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('released_pdf_artifact_id');
            $table->dropConstrainedForeignId('released_by');
            $table->dropColumn(['released_at', 'release_comment']);
        });
    }
};
