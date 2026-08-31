<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2: normalized Appointment lifecycle/outcome model, additive
 * alongside the existing `stage` column (App\Enums\AppointmentStage),
 * which is kept permanently, untouched, read-only historical/compatibility
 * data — its retirement is an explicit future decision, not part of
 * Phase 2. `status`/`outcome` start nullable; a later guarded migration
 * tightens `status` to NOT NULL once the legacy backfill (see
 * App\Console\Commands\BackfillLeadAppointmentStatus) verifies zero NULLs
 * and zero unrecognized legacy `stage` values remain. `outcome` stays
 * nullable indefinitely — not every Appointment has one recorded.
 *
 * `rescheduled_from_id` (single reschedule-linkage column, inverse is the
 * computed Appointment::replacedBy() relation) is a different concept from
 * `origin_type`/`origin_id` (which prior activity's outcome caused this
 * Appointment to be created — e.g. "Another Appointment Required" on a
 * completed Appointment). Never confused, never merged — see
 * App\Services\WorkflowTransitionService's docblock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('status')->nullable()->after('stage');
            $table->string('outcome')->nullable()->after('status');
            $table->timestamp('status_changed_at')->nullable()->after('stage_changed_at');
            $table->foreignId('rescheduled_from_id')->nullable()->after('id')
                ->constrained('appointments')->restrictOnDelete();
            $table->nullableMorphs('origin');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rescheduled_from_id');
            $table->dropColumn(['origin_type', 'origin_id', 'status', 'outcome', 'status_changed_at']);
        });
    }
};
