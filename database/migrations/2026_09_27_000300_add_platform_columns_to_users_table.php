<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Roles and the separation-of-duties rules the database itself enforces:
 * one OHA Lead, one OHA Assistant Lead, one Board Chairperson per
 * movement, and board members (and only board members) belong to a movement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('staff')->after('password');
            $table->string('title', 80)->nullable()->after('role');
            $table->foreignId('movement_id')->nullable()->after('title')->constrained()->restrictOnDelete();
            $table->boolean('is_chair')->default(false)->after('movement_id');
            $table->boolean('active')->default(true)->after('is_chair');
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_valid CHECK (role IN ('admin', 'staff', 'oha_lead', 'oha_asst_lead', 'gs', 'board'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_board_has_movement CHECK ((role = 'board') = (movement_id IS NOT NULL))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_chair_is_board CHECK (NOT is_chair OR role = 'board')");

        DB::statement("CREATE UNIQUE INDEX users_one_oha_lead ON users (role) WHERE role = 'oha_lead' AND active");
        DB::statement("CREATE UNIQUE INDEX users_one_oha_asst_lead ON users (role) WHERE role = 'oha_asst_lead' AND active");
        DB::statement('CREATE UNIQUE INDEX users_one_chair_per_movement ON users (movement_id) WHERE is_chair AND active');

        Schema::create('movement_user', function (Blueprint $table) {
            $table->foreignId('movement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['movement_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movement_user');

        DB::statement('DROP INDEX IF EXISTS users_one_chair_per_movement');
        DB::statement('DROP INDEX IF EXISTS users_one_oha_asst_lead');
        DB::statement('DROP INDEX IF EXISTS users_one_oha_lead');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('movement_id');
            $table->dropColumn(['role', 'title', 'is_chair', 'active']);
        });
    }
};
