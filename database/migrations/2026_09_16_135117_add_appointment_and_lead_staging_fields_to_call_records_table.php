<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pipeline Board V2 (Calls column): mirrors the existing `follow_up_at`/
 * `appointment_at` staging-field precedent already on this table — a
 * destination-specific board modal collects these values on the Call being
 * logged, and CallRoutingService::createAppointment()/createLead() copy
 * them into the resulting Appointment/Lead once routing decides to create
 * one. All nullable: every existing/legacy call-logging path (the real
 * CallRecordResource form, `Others` + any next_action) simply never sets
 * them, so nothing here is a new universal requirement.
 *
 * `follow_up_contact_mode` mirrors FollowUp's own existing, already-
 * approved `contact_mode` column (App\Enums\ContactMode) — no new business
 * concept, purely a transport column so the optional Contact Mode the
 * Calls -> Follow-Up modal collects can reach the FollowUp CallRoutingService
 * creates, since routing only ever sees the freshly re-fetched CallRecord
 * row, never the original form submission.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_records', function (Blueprint $table) {
            $table->string('appointment_mode')->nullable()->after('appointment_at');
            $table->string('appointment_person_meeting')->nullable()->after('appointment_mode');
            $table->string('appointment_location')->nullable()->after('appointment_person_meeting');
            $table->string('follow_up_contact_mode')->nullable()->after('follow_up_at');
            $table->string('lead_opportunity_title')->nullable()->after('follow_up_contact_mode');
        });
    }

    public function down(): void
    {
        Schema::table('call_records', function (Blueprint $table) {
            $table->dropColumn([
                'appointment_mode',
                'appointment_person_meeting',
                'appointment_location',
                'follow_up_contact_mode',
                'lead_opportunity_title',
            ]);
        });
    }
};
