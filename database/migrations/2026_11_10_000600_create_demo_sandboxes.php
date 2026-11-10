<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The demo site's private copies (App\Demo\Sandboxes): one row per visitor's sandbox,
 * whose own tables live in the schema demo_{key}. Only used on the demo site; the table
 * is empty everywhere else (and in each sandbox's own schema).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_sandboxes', function (Blueprint $table) {
            $table->id();
            $table->string('key', 32)->unique()->comment('The visitor’s private key (their cookie and their private link)');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('last_used_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_sandboxes');
    }
};
