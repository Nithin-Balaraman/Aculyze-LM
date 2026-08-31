<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2: the approved normalized Lead vocabulary (App\Enums\LeadStatus),
 * additive alongside the existing `stage` column (App\Enums\LeadStage),
 * which is kept permanently, untouched, read-only historical/compatibility
 * data. Starts nullable; a later guarded migration tightens to NOT NULL
 * once the legacy backfill verifies zero NULLs and zero unrecognized
 * legacy `stage` values remain (see
 * App\Console\Commands\BackfillLeadAppointmentStatus).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('status')->nullable()->after('stage');
            $table->timestamp('status_changed_at')->nullable()->after('stage_changed_at');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['status', 'status_changed_at']);
        });
    }
};
