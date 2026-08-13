<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('prospect_id')->constrained('prospects');
            // Nullable + unique: not every Appointment has to originate from
            // a call, but if it does, only one Appointment may originate
            // from a given call (duplicate-routing protection).
            $table->foreignId('call_record_id')->nullable()->unique()->constrained('call_records');

            $table->foreignId('assigned_to')->constrained('users');
            $table->foreignId('created_by')->constrained('users');

            $table->dateTime('appointment_at')->nullable();
            $table->string('stage')->default('appointment_made');
            $table->text('meeting_notes')->nullable();
            $table->text('outcome_notes')->nullable();

            // Updated only when `stage` changes — never on unrelated edits.
            // Required for accurate lifecycle reporting. See AGENTS.md
            // section 19.
            $table->timestamp('stage_changed_at')->nullable();

            $table->timestamps();

            $table->index(['assigned_to']);
            $table->index(['prospect_id']);
            $table->index(['stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
