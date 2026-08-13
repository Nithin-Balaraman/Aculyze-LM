<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // Mirrors the Leads "Lost" overlay (see
            // 2026_08_12_113636_add_lost_fields_to_leads_table.php): Lost is
            // an outcome layered on top of wherever the Appointment
            // currently sits in its normal stage progression, not a
            // replacement stage value.
            $table->boolean('is_lost')->default(false)->after('stage');
            $table->string('lost_at_stage')->nullable()->after('is_lost');
            $table->text('lost_reason')->nullable()->after('lost_at_stage');
            $table->timestamp('lost_at')->nullable()->after('lost_reason');

            $table->index(['is_lost']);
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex(['is_lost']);
            $table->dropColumn(['is_lost', 'lost_at_stage', 'lost_reason', 'lost_at']);
        });
    }
};
