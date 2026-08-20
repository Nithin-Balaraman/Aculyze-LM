<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            // Path relative to the 'local' disk (storage/app/private — see
            // config/filesystems.php), not the public 'avatars' disk.
            // 'local' already has 'serve' => true and no 'visibility' key
            // (defaults to private), so Laravel's own auto-registered
            // storage.local route already requires a valid signature to
            // serve it — no new access-control code needed for the file
            // itself, only for the "Download PDF" action that reads it.
            $table->string('pdf_path')->nullable()->after('notes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropColumn('pdf_path');
        });
    }
};
