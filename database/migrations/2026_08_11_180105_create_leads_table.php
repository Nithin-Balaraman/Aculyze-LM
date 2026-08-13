<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();

            $table->foreignId('prospect_id')->constrained('prospects');
            // Nullable + unique: only Requirement Identified calls route to
            // a Lead automatically, and only once per call.
            $table->foreignId('call_record_id')->nullable()->unique()->constrained('call_records');

            $table->foreignId('assigned_to')->constrained('users');
            $table->foreignId('created_by')->constrained('users');

            $table->string('stage')->default('requirement_collection');
            $table->string('temperature')->default('warm');
            $table->text('requirement_details')->nullable();
            $table->text('notes')->nullable();

            // Updated only when `stage` changes. Drives the 30-day stale
            // alert — see AGENTS.md sections 22-23.
            $table->timestamp('stage_changed_at')->nullable();

            $table->timestamps();

            $table->index(['assigned_to']);
            $table->index(['prospect_id']);
            $table->index(['stage']);
            $table->index(['temperature']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
