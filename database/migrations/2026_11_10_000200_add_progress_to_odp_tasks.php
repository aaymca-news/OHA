<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 2, ODP follow-up (10 Nov 2026): each ODP task's progress, reported by the
 * movement's National General Secretary or its assessors, and a "Done" confirmed by an
 * assessor. `done` stays the one flag counted everywhere: true only once confirmed.
 * Tasks already ticked done become Done, confirmed by whoever ticked them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('odp_tasks', function (Blueprint $table) {
            $table->string('progress', 12)->default('not_started')->comment('not_started, in_progress, done or blocked; done counts once confirmed');
            $table->text('progress_note')->nullable()->comment('What was done, or what is in the way');
            $table->foreignId('evidence_id')->nullable()->comment('The document in the evidence library that shows it done')->constrained('evidence')->nullOnDelete();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reported_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->comment('The assessor who confirmed it done')->constrained('users')->nullOnDelete();
            $table->timestampTz('confirmed_at')->nullable();
            $table->string('returned_reason', 2000)->nullable()->comment('Why an assessor did not confirm it done');
            $table->index('evidence_id');
        });

        DB::table('odp_tasks')->where('done', true)->update([
            'progress' => 'done',
            'confirmed_by' => DB::raw('edited_by'),
            'confirmed_at' => DB::raw('COALESCE(edited_at, updated_at)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('odp_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('evidence_id');
            $table->dropConstrainedForeignId('reported_by');
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropColumn(['progress', 'progress_note', 'reported_at', 'confirmed_at', 'returned_reason']);
        });
    }
};
