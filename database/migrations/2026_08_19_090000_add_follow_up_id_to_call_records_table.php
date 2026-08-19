<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_records', function (Blueprint $table) {
            // Set only on the Call Record a Follow-Up's "Completed" action
            // creates behind the scenes (see App\Models\CallRecord::
            // scopeDirectlyLogged()) — marks it as existing purely to drive
            // App\Services\CallRoutingService, with zero visibility
            // anywhere else in the app (Activity Log, KPIs, Pipeline
            // Pulse, employee-deletion counts, call history).
            $table->foreignId('follow_up_id')->nullable()->after('appointment_at')->constrained('follow_ups');
        });
    }

    public function down(): void
    {
        Schema::table('call_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('follow_up_id');
        });
    }
};
