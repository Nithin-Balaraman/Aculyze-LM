<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposals', function (Blueprint $table) {
            $table->id();

            // One Proposal per Lead for this initial version — see AGENTS.md
            // section 26 and section 61 Question 7. Enforced with a unique
            // index; revisit if multi-proposal-per-lead is ever required.
            $table->foreignId('lead_id')->unique()->constrained('leads');
            // Denormalized from the Lead's prospect at creation time, purely
            // to avoid joining through leads for every Prospect-scoped query.
            $table->foreignId('prospect_id')->constrained('prospects');

            $table->foreignId('assigned_to')->constrained('users');
            $table->foreignId('created_by')->constrained('users');

            $table->string('stage')->default('being_prepared');
            $table->string('outcome')->nullable();
            $table->decimal('value', 12, 2)->nullable();
            $table->date('sent_at')->nullable();
            $table->text('notes')->nullable();

            // Updated only when `stage` (or the final `outcome`) changes.
            // Drives the 20-day stale alert — see AGENTS.md sections 27-28.
            $table->timestamp('stage_changed_at')->nullable();

            $table->timestamps();

            $table->index(['assigned_to']);
            $table->index(['prospect_id']);
            $table->index(['stage']);
            $table->index(['outcome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposals');
    }
};
