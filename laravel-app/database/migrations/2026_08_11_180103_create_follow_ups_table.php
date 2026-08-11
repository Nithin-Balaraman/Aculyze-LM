<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follow_ups', function (Blueprint $table) {
            $table->id();

            $table->foreignId('prospect_id')->constrained('prospects');
            // Nullable: a follow-up can also be logged manually (e.g. an
            // employee schedules a plain follow-up without a call), but the
            // automatic routing path always sets this and enforces one
            // follow-up per originating call via the unique index below.
            $table->foreignId('call_record_id')->nullable()->unique()->constrained('call_records');
            $table->foreignId('user_id')->constrained('users');

            $table->dateTime('follow_up_at')->nullable();
            $table->string('reason')->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('pending');

            $table->timestamps();

            $table->index(['prospect_id']);
            $table->index(['user_id']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_ups');
    }
};
