<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: additive, nullable-first columns on call_records for:
 * - Profile Sent structured tracking (CallOutcome::ProfileRequested)
 * - CallNextAction (meaningful only when outcome = Other)
 * - explicit Correct Outcome (correction_reason, outcome_corrected_at)
 *
 * No backfill needed — every pre-Phase-3 row is correctly left NULL on all
 * of these (they either predate the concept entirely, or the outcome/next
 * action they'd apply to was never captured historically).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_records', function (Blueprint $table) {
            $table->string('profile_sent_status')->nullable()->after('appointment_at');
            $table->dateTime('profile_sent_at')->nullable()->after('profile_sent_status');
            $table->string('profile_sent_mode')->nullable()->after('profile_sent_at');
            $table->text('profile_sent_notes')->nullable()->after('profile_sent_mode');
            $table->string('next_action')->nullable()->after('outcome');
            $table->text('correction_reason')->nullable()->after('notes');
            $table->dateTime('outcome_corrected_at')->nullable()->after('processed_at');
        });
    }

    public function down(): void
    {
        Schema::table('call_records', function (Blueprint $table) {
            $table->dropColumn([
                'profile_sent_status',
                'profile_sent_at',
                'profile_sent_mode',
                'profile_sent_notes',
                'next_action',
                'correction_reason',
                'outcome_corrected_at',
            ]);
        });
    }
};
