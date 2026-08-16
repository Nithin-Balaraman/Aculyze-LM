<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_records', function (Blueprint $table) {
            $table->renameColumn('callback_at', 'follow_up_at');
        });

        Schema::table('call_records', function (Blueprint $table) {
            // Redundant once the field's visibility on the form is driven
            // by the selected outcome (CallOutcome::routesToFollowUp())
            // rather than a manual toggle — nothing left to require.
            $table->dropColumn('callback_required');

            // Mirrors follow_up_at, but for outcomes that route to an
            // Appointment instead (CallOutcome::routesToAppointment()).
            // Nullable/optional — the caller may not know the exact time
            // yet when logging the call.
            $table->dateTime('appointment_at')->nullable()->after('follow_up_at');
        });
    }

    public function down(): void
    {
        Schema::table('call_records', function (Blueprint $table) {
            $table->dropColumn('appointment_at');
            $table->boolean('callback_required')->default(false)->after('notes');
        });

        Schema::table('call_records', function (Blueprint $table) {
            $table->renameColumn('follow_up_at', 'callback_at');
        });
    }
};
