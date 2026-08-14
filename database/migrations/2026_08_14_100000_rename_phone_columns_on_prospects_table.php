<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospects', function (Blueprint $table) {
            $table->renameColumn('phone_primary', 'telephone');
            $table->renameColumn('phone_secondary', 'mobile');
        });
    }

    public function down(): void
    {
        Schema::table('prospects', function (Blueprint $table) {
            $table->renameColumn('telephone', 'phone_primary');
            $table->renameColumn('mobile', 'phone_secondary');
        });
    }
};
