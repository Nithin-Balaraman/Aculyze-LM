<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // "Lost" is an outcome layered on top of wherever the Lead
            // currently sits in its normal stage progression — not a
            // replacement stage value, so the existing LeadStage
            // progression/eligibility logic keeps working unchanged.
            // See Change Request "Decision 2".
            $table->boolean('is_lost')->default(false)->after('temperature');
            $table->string('lost_at_stage')->nullable()->after('is_lost');
            $table->text('lost_reason')->nullable()->after('lost_at_stage');
            $table->timestamp('lost_at')->nullable()->after('lost_reason');

            $table->index(['is_lost']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['is_lost']);
            $table->dropColumn(['is_lost', 'lost_at_stage', 'lost_reason', 'lost_at']);
        });
    }
};
