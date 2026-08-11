<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_records', function (Blueprint $table) {
            $table->id();

            $table->foreignId('prospect_id')->constrained('prospects');
            // The employee who actually made the call. This never changes,
            // even if the Prospect is later reassigned to someone else.
            $table->foreignId('user_id')->constrained('users');

            $table->dateTime('called_at');
            $table->string('outcome');
            $table->text('notes')->nullable();
            $table->boolean('callback_required')->default(false);
            $table->dateTime('callback_at')->nullable();
            $table->string('contact_person_spoken_to')->nullable();
            $table->string('phone_called')->nullable();

            // Set once App\Services\CallRoutingService has created the
            // downstream Follow-Up/Appointment/Lead records for this call,
            // so routing can never run twice for the same call. See
            // AGENTS.md section 16.
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            $table->index(['prospect_id']);
            $table->index(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_records');
    }
};
