<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2: Follow-Up reschedule/history foundation.
 *
 * `rescheduled_from_id` is the ONLY reschedule-linkage column — the
 * inverse ("what replaced this one") is a computed Eloquent relation
 * (FollowUp::replacedBy()), not a second physical FK, so the two halves of
 * the same relationship can never drift/contradict each other.
 *
 * `origin_type`/`origin_id` is a SEPARATE concept: which prior workflow
 * activity (e.g. a Demo, via "More Time / Discussion") caused this
 * Follow-Up to be created as the next business action — never confused
 * with rescheduling. Uses the stable morph-map aliases registered in
 * AppServiceProvider::boot(), never raw class names.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('follow_ups', function (Blueprint $table) {
            $table->foreignId('rescheduled_from_id')->nullable()->after('id')
                ->constrained('follow_ups')->restrictOnDelete();
            $table->nullableMorphs('origin');
        });
    }

    public function down(): void
    {
        Schema::table('follow_ups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rescheduled_from_id');
            $table->dropColumn(['origin_type', 'origin_id']);
        });
    }
};
