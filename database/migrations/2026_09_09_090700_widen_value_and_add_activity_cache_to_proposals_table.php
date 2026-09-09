<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4A-3.1 (locked Decisions 15/19). Two independent changes:
 *
 * 1. Widen `value` from DECIMAL(12,2) to DECIMAL(18,2) — matches
 *    ProposalVersion.grand_total's own precision exactly, and prevents a
 *    strict-mode overflow the day a winning Version's grand_total is
 *    copied into it on Accepted (4A-3.4). Non-destructive widening only —
 *    every existing value fits unchanged.
 *
 * 2. Add `last_client_activity_at` — a service-owned cache column, written
 *    ONLY by the not-yet-implemented ProposalClientResponseService (4A-3.4),
 *    and only for the More Time and Other -> Create Follow-Up response
 *    types (locked Decision 18) — never for Other -> Await Further Contact,
 *    never by any form/board write, never backfilled here. Combined with
 *    `stage_changed_at` (see the accompanying Proposal::booted() hook
 *    correction) to compute staleness once qualifying client activity can
 *    reset the clock without a genuine stage change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->decimal('value', 18, 2)->nullable()->change();
            $table->timestamp('last_client_activity_at')->nullable()->after('stage_changed_at');
        });
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropColumn('last_client_activity_at');
            $table->decimal('value', 12, 2)->nullable()->change();
        });
    }
};
