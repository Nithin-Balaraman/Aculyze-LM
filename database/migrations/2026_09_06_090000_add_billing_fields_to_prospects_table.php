<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4A-2.1 (locked Decision 1 + addendum): current master-data fields
 * needed to seed a new Proposal's V1 commercial snapshot. Deliberately NOT
 * backfilled from any existing column — every existing Prospect starts
 * with all three as NULL, never inferring a value from `address`/`state`
 * (those are general-purpose mailing fields, never proven to be the formal
 * GST billing address/state) and never fabricating a GSTIN. Managers
 * populate or verify these only when reliable information is actually
 * available (Master BA Specification Principle P8, "no fabricated
 * history" — the same principle 4A-1's backfill already followed).
 *
 * place_of_supply is deliberately NOT added here — it is transaction/
 * proposal-specific and can differ between Proposals for the same
 * Prospect, so it belongs solely to ProposalVersion.place_of_supply_snapshot
 * (Manager enters/confirms it per Version), never Prospect master data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospects', function (Blueprint $table) {
            $table->string('gstin')->nullable()->after('pincode');
            $table->string('billing_address')->nullable()->after('gstin');
            $table->string('billing_state')->nullable()->after('billing_address');
        });
    }

    public function down(): void
    {
        Schema::table('prospects', function (Blueprint $table) {
            $table->dropColumn(['gstin', 'billing_address', 'billing_state']);
        });
    }
};
