<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4A-1: adds the Proposal parent's pointers into its own Version
 * history (Master BA Specification sections 3.1/7). Both are nullable —
 * every pre-existing Proposal gets current_version_id populated by
 * App\Console\Commands\BackfillProposalVersions in the same deployment,
 * never left dangling in practice, but the column itself must tolerate a
 * brief NULL between "column added" and "backfill run".
 *
 * proposal_number is added now (avoiding a second ALTER TABLE later) but
 * deliberately left NULL for every existing row — the human-readable
 * numbering format/sequence is explicitly NOT locked yet (section 17);
 * inventing one now would be exactly the kind of fabricated
 * technical-only value Principle P8 warns against for real business data.
 *
 * "Proposal.outcome = Won requires a valid winning_version_id" (Data
 * Integrity Rules, section 13) is DELIBERATELY NOT enforced as a DB CHECK
 * constraint here, despite being single-table and therefore something
 * MariaDB can otherwise express: empirically, adding
 * `CHECK (outcome != 'won' OR winning_version_id IS NOT NULL)` against both
 * local MariaDB 10.11.14 and (per the Phase 4A production audit) production
 * MariaDB 11.8.8 breaks every EXISTING way this app already marks a
 * Proposal Won — the current Filament stage/outcome form and
 * WorkflowTransitionService have no concept of winning_version_id at all,
 * since the real Won/approval workflow that populates it is 4A-2 (approval
 * UI/actions), explicitly out of scope for 4A-1. Adding the CHECK now would
 * make it impossible to mark any Proposal Won in production until 4A-2
 * ships — a regression 4A-1 must not introduce. This invariant is deferred
 * to 4A-2 alongside the "winning_version_id actually belongs to THIS
 * Proposal" cross-table check (which MariaDB cannot express as a CHECK at
 * all) — both become real once 4A-2 builds the workflow that actually sets
 * winning_version_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->string('proposal_number')->nullable()->after('id');
            $table->foreignId('current_version_id')->nullable()->after('outcome')->constrained('proposal_versions')->nullOnDelete();
            $table->foreignId('winning_version_id')->nullable()->after('current_version_id')->constrained('proposal_versions')->nullOnDelete();
        });

        Schema::table('proposals', function (Blueprint $table) {
            $table->unique(['organization_id', 'proposal_number']);
        });
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'proposal_number']);
            $table->dropConstrainedForeignId('winning_version_id');
            $table->dropConstrainedForeignId('current_version_id');
            $table->dropColumn('proposal_number');
        });
    }
};
