<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('follow_ups', function (Blueprint $table) {
            // Nullable/optional — purely informational, no routing logic
            // keys off it (see App\Enums\ContactMode).
            $table->string('contact_mode')->nullable()->after('follow_up_at');
        });
    }

    public function down(): void
    {
        Schema::table('follow_ups', function (Blueprint $table) {
            $table->dropColumn('contact_mode');
        });
    }
};
