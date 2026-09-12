<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4A-3.5 cutover: the final locked DB invariant — "Proposal.outcome =
 * Won requires a valid winning_version_id" (Data Integrity Rules, section
 * 13) — deliberately deferred at 4A-1 (see
 * 2026_09_05_090100_add_version_pointers_to_proposals_table's own docblock)
 * because every existing runtime writer of Won at the time (the generic
 * Filament stage/outcome form, PipelineBoard's drag handlers) had no concept
 * of winning_version_id at all. That is no longer true: 4A-3.5 removed every
 * one of those writers — ProposalClientResponseService::recordAccepted() is
 * now the ONLY runtime path that can ever set outcome=Won, and it always
 * sets winning_version_id in the exact same write.
 *
 * Prerequisite FK change (empirically discovered, not optional): MariaDB
 * 10.11.14 refuses ANY CHECK constraint that references a column carrying
 * an `ON DELETE SET NULL` foreign key action — error 1901, "Function or
 * expression 'winning_version_id' cannot be used in the CHECK clause" —
 * reproduced directly against local MariaDB even for a trivial tautological
 * CHECK, regardless of the expression's own content. This is not a syntax
 * workaround to route around: it is MariaDB correctly refusing to let a
 * CHECK coexist with an FK action that could silently null the column
 * outside normal constraint validation — exactly the hole that would
 * otherwise let a deleted winning Version leave a Won Proposal with a NULL
 * winner, undetected. `winning_version_id` is tightened from nullOnDelete to
 * restrictOnDelete first, in the same migration, as the direct enabling
 * prerequisite for the CHECK below (no ProposalVersion row is ever deleted
 * by any runtime path in this codebase today — confirmed by inventory — so
 * this is a pure tightening with no behavioral impact on any existing
 * flow). `current_version_id` is NOT touched: no invariant in this phase
 * requires it, and Section H of the kickoff is explicit that this migration
 * must add ONLY the one CHECK plus its direct prerequisite.
 *
 * Constraint name kept short and explicit
 * (`proposals_won_requires_winning_version`, 40 chars) — well within
 * MariaDB's 64-character identifier limit. Uses the exact persisted
 * ProposalOutcome::Won value ('won'). A NULL outcome or a NULL
 * winning_version_id on a non-Won outcome both satisfy the CHECK (SQL
 * three-valued logic: `NULL != 'won'` is UNKNOWN, and a CHECK only fails on
 * a definite FALSE) — verified directly against local MariaDB, see the
 * Phase 4A-3.5 implementation report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropForeign(['winning_version_id']);
        });

        Schema::table('proposals', function (Blueprint $table) {
            $table->foreign('winning_version_id')
                ->references('id')->on('proposal_versions')
                ->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE proposals ADD CONSTRAINT proposals_won_requires_winning_version '.
            "CHECK (outcome != 'won' OR winning_version_id IS NOT NULL)"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE proposals DROP CONSTRAINT proposals_won_requires_winning_version');

        Schema::table('proposals', function (Blueprint $table) {
            $table->dropForeign(['winning_version_id']);
        });

        Schema::table('proposals', function (Blueprint $table) {
            $table->foreign('winning_version_id')
                ->references('id')->on('proposal_versions')
                ->nullOnDelete();
        });
    }
};
