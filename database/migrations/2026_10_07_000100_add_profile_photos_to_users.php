<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each person may add a profile photo. It is cropped square and kept on the private
 * disk; it replaces their initials wherever their name appears.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_disk', 20)->nullable()->comment('Disk of the profile photo; NULL when there is none');
            $table->string('avatar_path')->nullable()->comment('Profile photo, 256×256 JPEG named by its SHA-256; NULL shows initials');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['avatar_disk', 'avatar_path']);
        });
    }
};
