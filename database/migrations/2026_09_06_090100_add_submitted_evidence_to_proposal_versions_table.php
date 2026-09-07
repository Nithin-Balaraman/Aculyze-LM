<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4A-2.1 (locked Decision 2 + addendum): the real formal Manager
 * submission evidence — genuinely missing from 4A-1's schema, not a
 * duplicate of `manager_reviewed_by`/`manager_reviewed_at`/
 * `manager_review_comment`. Those three came from an earlier, since-
 * corrected workflow assumption (Employee submits -> Manager reviews ->
 * Senior Manager approves) and are NOT used by the actual locked workflow
 * (Manager prepares -> Manager formally submits -> Senior Manager
 * Approves/Returns). They stay nullable and untouched/unpopulated by any
 * 4A-2 code — deprecated in place, not repurposed, not dropped here.
 *
 * Nullable, and never backfilled for any existing (including legacy
 * backfilled) ProposalVersion row — none of the 6 real production rows
 * ever had a genuine "Manager submits" event to record.
 *
 * FK/delete-behavior matches the existing actor columns on this same
 * table (manager_reviewed_by/approved_by/returned_by): plain
 * ->constrained('users') with no explicit onDelete, i.e. RESTRICT by
 * InnoDB default — consistent, not a new convention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposal_versions', function (Blueprint $table) {
            $table->foreignId('submitted_by')->nullable()->after('currency_code')->constrained('users');
            $table->timestamp('submitted_at')->nullable()->after('submitted_by');
        });
    }

    public function down(): void
    {
        Schema::table('proposal_versions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropColumn('submitted_at');
        });
    }
};
