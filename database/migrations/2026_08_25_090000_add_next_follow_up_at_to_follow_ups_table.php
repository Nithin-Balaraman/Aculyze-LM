<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('follow_ups', function (Blueprint $table) {
            // Optional — "Followed Up At" (the existing follow_up_at column)
            // now records when this interaction actually happened, so a
            // separate, always-visible field is needed for scheduling when
            // to next work this same record. Distinct from the ephemeral
            // `new_follow_up_at` form field used only inside the Completed
            // flow, which never persists here — that one spawns a whole new
            // FollowUp row via CallRoutingService instead.
            $table->dateTime('next_follow_up_at')->nullable()->after('follow_up_at');
        });
    }

    public function down(): void
    {
        Schema::table('follow_ups', function (Blueprint $table) {
            $table->dropColumn('next_follow_up_at');
        });
    }
};
