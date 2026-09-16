<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pipeline Board V2 (Calls column, locked design section 4A): new,
 * additive Appointment scheduling fields collected by the destination-
 * specific Calls -> Appointment modal. All nullable at the DB level —
 * required-ness is enforced only inside that one modal's own form (see
 * PipelineBoard's callToAppointmentFormSchema()), never as a model-level
 * guard, so standalone AppointmentResource creation, the legacy
 * `Others` + `CreateAppointment` routing path, and any future Follow-Up ->
 * Appointment V2 work are unaffected and remain fully backward-compatible
 * with existing records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('mode')->nullable()->after('appointment_at');
            $table->string('person_meeting')->nullable()->after('mode');
            $table->string('location')->nullable()->after('person_meeting');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['mode', 'person_meeting', 'location']);
        });
    }
};
