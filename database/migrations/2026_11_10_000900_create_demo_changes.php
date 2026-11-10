<?php

use App\Demo\DemoChanges;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The demo site's record of changes and who made them (DemoChanges), so a visitor's work
 * can be undone when their account is deleted. The recording itself is set up on the demo
 * site only (and in its main schema only); elsewhere this table stays empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_changes', function (Blueprint $table) {
            $table->id();
            $table->string('visitor_key', 32)->nullable()->index();
            $table->string('table_name', 128);
            $table->char('op', 1);
            $table->jsonb('old_row')->nullable();
            $table->jsonb('new_row')->nullable();
            $table->timestampTz('changed_at');
        });

        if (config('oha.demo') && DB::selectOne('select current_schema() as s')->s === 'public') {
            DemoChanges::install();
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS public.demo_record_change() CASCADE');
        Schema::dropIfExists('demo_changes');
    }
};
