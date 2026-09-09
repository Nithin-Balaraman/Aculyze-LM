<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4A-3.1 (schema foundation only — populated by the 4A-3.3 send
 * service). One row per Proposal attachment selected into a send's
 * immutable manifest (locked Decision 24). The physical archive is
 * content-addressed (conceptual path
 * `proposal-send-attachments/{organization_id}/{sha256}`), but manifest
 * cardinality is a SEPARATE concern from byte-level deduplication: two
 * selected attachments with identical bytes but different original
 * filenames must both appear as their own manifest row. There is
 * deliberately NO unique constraint on `checksum_sha256` (alone or combined
 * with `proposal_send_id`) — only an index, for dedup lookup at the
 * storage-write layer. No separate archive-object table exists; the
 * deterministic path itself is the dedup mechanism.
 *
 * `proposal_send_id` is cascadeOnDelete — decomposition-only child with no
 * independent significance, mirroring proposal_version_lines' own FK to
 * proposal_versions (nothing provides a delete path for a ProposalSend at
 * all; this is defensive referential cleanup only, not a
 * historical-integrity concession).
 *
 * created_at only (no updated_at) — immutable row, mirrors audit_events'
 * own precedent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposal_send_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('proposal_send_id')->constrained('proposal_sends')->cascadeOnDelete();
            $table->char('checksum_sha256', 64);
            $table->string('archived_path');
            $table->string('original_filename');
            $table->string('mime_type');
            $table->unsignedBigInteger('byte_size');
            $table->timestamp('created_at')->useCurrent();

            // Explicit short index name: the default composite index name
            // is close to MySQL/MariaDB's 64-character identifier limit,
            // same precedent as proposal_version_line_tax_components'.
            $table->index(['organization_id', 'checksum_sha256'], 'psa_organization_id_checksum_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_send_attachments');
    }
};
