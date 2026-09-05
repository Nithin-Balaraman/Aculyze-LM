<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4A-1: the immutable commercial-document snapshot core (Master BA
 * Specification section 3.2). Proposal remains the long-lived opportunity;
 * this table owns one exact commercial version (V1, V2, V3...) of it.
 *
 * Draft-lock: `draft_lock_key` is a STORED generated column that evaluates
 * to `proposal_id` only while `lifecycle_status = 'draft'`, and to NULL
 * otherwise. A UNIQUE index on it enforces "at most one active Draft
 * version per Proposal" at the database level — MariaDB/MySQL both treat
 * every NULL in a unique index as distinct from every other NULL, so any
 * number of non-Draft rows coexist freely; only two simultaneous Draft
 * rows for the SAME Proposal collide. This is the MariaDB 11.8-confirmed
 * strategy from the Phase 4A technical preflight (neither MySQL nor
 * MariaDB support a true partial/filtered unique index the way
 * PostgreSQL/SQL Server do, so this generated-column technique is the
 * correct substitute, not a workaround of last resort).
 *
 * Supersession (`superseded_at`/`superseded_by_version_id`) is deliberately
 * NOT a lifecycle_status value — see App\Enums\ProposalVersionLifecycle's
 * own docblock and Master BA Specification section 15's explicit override.
 * The self-referencing FK is created inline (same pattern already proven
 * in this codebase by demos.rescheduled_from_id): a table can reference
 * its own not-yet-fully-built primary key within one CREATE TABLE
 * statement, since the table definition is validated as a whole.
 *
 * `proposal_id` uses restrictOnDelete(): a Proposal with any real
 * commercial history can never be casually deleted out from under it
 * (Principle P1 — "immutable commercial truth"). See the Phase 4A-1
 * completion report for the full FK/delete-behavior reasoning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposal_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('proposal_id')->constrained('proposals')->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('lifecycle_status');

            // Phase 4A-1 legacy backfill marker — true for every V1 created
            // from pre-Phase-4 production data (see
            // App\Console\Commands\BackfillProposalVersions), false for
            // every version created through the real Phase 4A-2+ workflow.
            // Lets reporting/UI distinguish "Sent with no recorded approval
            // chain because it predates Phase 4" from a genuine
            // post-Phase-4 Sent version that has one.
            $table->boolean('is_legacy_backfill')->default(false);

            $table->timestamp('superseded_at')->nullable();
            $table->foreignId('superseded_by_version_id')->nullable()->constrained('proposal_versions')->nullOnDelete();

            // Customer/tax snapshot (section 6.3) — frozen at the moment of
            // freeze/send. Prospect.gstin etc. remain current master data
            // and must never be dynamically re-read into an existing
            // Version later.
            $table->string('customer_name_snapshot')->nullable();
            $table->string('customer_gstin_snapshot')->nullable();
            $table->text('billing_address_snapshot')->nullable();
            $table->string('billing_state_snapshot')->nullable();
            $table->string('place_of_supply_snapshot')->nullable();

            // Commercial/document terms (section 4.2's "commercial
            // content" definition).
            $table->text('payment_terms')->nullable();
            $table->text('validity_terms')->nullable();
            $table->text('scope_notes')->nullable();

            // Commercial summary (sections 6.1/6.2) — DECIMAL(18,2), the
            // locked persisted-monetary-amount precision. Nullable: a
            // legacy backfilled version may legitimately have no totals
            // beyond grand_total (zero fabricated line-item history).
            $table->decimal('subtotal', 18, 2)->nullable();
            $table->decimal('total_discount', 18, 2)->nullable();
            $table->decimal('tax_total', 18, 2)->nullable();
            $table->decimal('grand_total', 18, 2)->nullable();
            $table->string('currency_code', 3)->default('INR');

            // Workflow evidence (section 5.1) — field names match the
            // spec's own table exactly. Left entirely NULL for every
            // legacy-backfilled version: no fabricated reviewer/approver
            // identity or timestamp (Principle P8).
            $table->foreignId('manager_reviewed_by')->nullable()->constrained('users');
            $table->timestamp('manager_reviewed_at')->nullable();
            $table->text('manager_review_comment')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_comment')->nullable();
            $table->foreignId('returned_by')->nullable()->constrained('users');
            $table->timestamp('returned_at')->nullable();
            $table->text('return_reason')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            $table->unsignedBigInteger('draft_lock_key')
                ->nullable()
                ->storedAs("case when lifecycle_status = 'draft' then proposal_id else null end");

            $table->unique(['proposal_id', 'version_number']);
            $table->unique('draft_lock_key');
            $table->index(['organization_id', 'proposal_id']);
            $table->index(['organization_id', 'lifecycle_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_versions');
    }
};
