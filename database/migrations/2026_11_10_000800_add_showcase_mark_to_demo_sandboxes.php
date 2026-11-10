<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The demo site: how far into the showcase's audit trail a visitor's copy goes (the last
 * entry it copied). Later entries in the showcase are new work to bring in; later
 * entries in the copy are the visitor's own work. Empty anywhere but the demo site.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('demo_sandboxes', function (Blueprint $table) {
            $table->unsignedBigInteger('showcase_mark')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('demo_sandboxes', function (Blueprint $table) {
            $table->dropColumn('showcase_mark');
        });
    }
};
