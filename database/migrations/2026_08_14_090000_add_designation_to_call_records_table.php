<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_records', function (Blueprint $table) {
            $table->string('designation')->nullable()->after('contact_person_spoken_to');
        });
    }

    public function down(): void
    {
        Schema::table('call_records', function (Blueprint $table) {
            $table->dropColumn('designation');
        });
    }
};
