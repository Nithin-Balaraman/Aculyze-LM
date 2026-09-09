<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4A-3.1 (schema foundation only — populated by the 4A-3.4 Accepted
 * path). One provider-independent Pending handoff per Accepted event
 * (locked Decisions 13/15). No external billing API call exists in 4A-3;
 * `attempts`/`last_attempt_at`/`last_error` are forward-compatible columns
 * for whichever later phase adds the real provider integration, added now
 * to avoid a second ALTER TABLE later — mirrors this schema's existing
 * `proposal_number` precedent.
 *
 * The exactly-once backstop is `UNIQUE(accepted_response_id)`, NOT
 * `UNIQUE(proposal_id)` and NOT `UNIQUE(winning_version_id)` alone —
 * deliberately: a Proposal-scoped or Version-scoped uniqueness would
 * permanently forbid a second handoff after a future Reopen produces a
 * second legitimate Accepted event, which Decision 15 explicitly warns
 * against ("not force an unnecessary 'one handoff per Proposal forever'
 * assumption"). Each Accepted response row is itself a distinct event, so
 * tying uniqueness to it remains exactly-once per win while staying open to
 * a future second win. `proposal_id`/`winning_version_id` are retained as
 * denormalized reference columns only, not uniqueness backstops.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposal_billing_handoffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('proposal_id')->constrained('proposals')->restrictOnDelete();
            $table->foreignId('winning_version_id')->constrained('proposal_versions')->restrictOnDelete();
            $table->foreignId('accepted_response_id')->constrained('proposal_client_responses')->restrictOnDelete();
            $table->string('idempotency_key');
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique('accepted_response_id');
            $table->unique('idempotency_key');
            $table->index(['organization_id', 'proposal_id'], 'pbh_organization_id_proposal_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_billing_handoffs');
    }
};
