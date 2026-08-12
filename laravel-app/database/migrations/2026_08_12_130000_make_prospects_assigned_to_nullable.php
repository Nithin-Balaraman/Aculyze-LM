<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UX Fixes Batch Issue 5, Option A ("Keep Companies"): a Prospect assigned
 * to a deleted employee must stay in the system, unassigned rather than
 * reassigned to a specific person (Pre-Answered Question 3). The column was
 * previously NOT NULL, so that state genuinely could not be represented
 * without this schema change — reported per the batch's instruction not to
 * silently work around a non-nullable column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospects', function (Blueprint $table) {
            $table->foreignId('assigned_to')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('prospects', function (Blueprint $table) {
            $table->foreignId('assigned_to')->nullable(false)->change();
        });
    }
};
