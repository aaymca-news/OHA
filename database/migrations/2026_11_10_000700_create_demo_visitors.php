<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The demo site's visitors: each registers (name, email, password) and gets a demo of
 * their own (sandbox_key). Not the platform's users: an account here is only the key to
 * one demo. With the single-use links that let a visitor choose a new password. Empty
 * anywhere but the demo site.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_visitors', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('email')->unique();
            $table->string('password');
            $table->string('sandbox_key', 32)->nullable()->index();
            $table->timestamps();
            $table->index('updated_at');
        });

        Schema::create('demo_visitor_password_resets', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_visitor_password_resets');
        Schema::dropIfExists('demo_visitors');
    }
};
