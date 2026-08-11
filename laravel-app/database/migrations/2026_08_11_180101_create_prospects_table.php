<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prospects', function (Blueprint $table) {
            $table->id();

            $table->string('company_name');
            $table->string('contact_person')->nullable();
            $table->string('designation')->nullable();
            $table->string('phone_primary')->nullable();
            $table->string('phone_secondary')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('address')->nullable();
            $table->string('locality')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('pincode')->nullable();
            $table->string('industry')->nullable();
            $table->string('source')->nullable();
            $table->text('notes')->nullable();

            // Who is currently responsible for this prospect vs. who originally
            // created it — these diverge once Admin reassigns the record.
            $table->foreignId('assigned_to')->constrained('users');
            $table->foreignId('created_by')->constrained('users');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['assigned_to']);
            $table->index(['company_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prospects');
    }
};
