<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flag-as-Incorrect feature: when a Call's original outcome already
 * created real downstream history (a Follow-Up/Appointment/Lead —
 * CallRecord::deletionBlockers() non-empty), Correct Outcome is no longer
 * offered at all (it would silently leave stale/duplicate downstream
 * records behind); instead the reviewer flags the Call for Saji's manual
 * review and cleanup.
 *
 * Mirrors the exact naming/shape convention the Correct Outcome fields
 * already established here (correction_reason + outcome_corrected_at,
 * see 2026_09_01_090000_add_phase3_fields_to_call_records_table.php) —
 * a timestamp doubles as the "is it flagged" boolean (non-null = yes,
 * consistent with outcome_corrected_at's own role), plus an optional
 * free-text reason. No "flagged_by" column: the existing correction
 * fields don't track a "corrected_by" user either, so this stays
 * consistent with that precedent rather than introducing a new one only
 * for this feature. Additive and nullable-first — no backfill needed,
 * every existing row is correctly NULL (the concept didn't exist before).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_records', function (Blueprint $table) {
            $table->text('flag_reason')->nullable()->after('correction_reason');
            $table->dateTime('flagged_incorrect_at')->nullable()->after('outcome_corrected_at');
        });
    }

    public function down(): void
    {
        Schema::table('call_records', function (Blueprint $table) {
            $table->dropColumn(['flag_reason', 'flagged_incorrect_at']);
        });
    }
};
