<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Path relative to the 'avatars' disk (public/avatars — see
            // config/filesystems.php), not storage/app/public. Hostinger
            // deploys have no SSH access to run `storage:link`, so avatars
            // are written straight into the public webroot and served
            // directly, with no symlink involved.
            $table->string('avatar')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('avatar');
        });
    }
};
