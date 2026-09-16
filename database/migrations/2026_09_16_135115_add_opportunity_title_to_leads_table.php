<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pipeline Board V2 (Calls column, locked design section D1): a Prospect
 * may have multiple independent Leads (see MultipleLeadsPerCompanyTest), so
 * each needs a human-readable identity distinguishing one opportunity from
 * another on the same company. Nullable at the DB level deliberately —
 * existing Lead rows are never backfilled with a fabricated title (no
 * approved historical value exists for them); it is enforced as required
 * only at the application layer for newly-created Leads going through the
 * V2 Calls -> Lead flow (see CallRoutingService::createLead() and
 * PipelineBoard's destination-specific Create Lead modal).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('opportunity_title')->nullable()->after('prospect_id');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('opportunity_title');
        });
    }
};
