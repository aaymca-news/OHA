<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 2, ODP follow-up, step 5 (10 Nov 2026):
 *   - the annual review of a signed ODP, before the next assessment: what was achieved,
 *     what was not and why, and the lessons, with the task counts as they stood then;
 *   - carrying unfinished tasks into the next assessment: the copy is marked where it
 *     came from, the original where it went.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('odp_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('achieved');
            $table->text('not_achieved')->comment('What was not achieved, and why');
            $table->text('lessons')->nullable();
            $table->unsignedSmallInteger('tasks_done')->comment('Tasks confirmed done when the review was written');
            $table->unsignedSmallInteger('tasks_total');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::table('odp_tasks', function (Blueprint $table) {
            $table->foreignId('carried_from_task_id')->nullable()->comment('Set on a task carried over: the unfinished task it continues')->constrained('odp_tasks')->nullOnDelete();
            $table->foreignId('carried_to_assessment_id')->nullable()->comment('Set on an unfinished task carried into a later assessment')->constrained('assessments')->nullOnDelete();
            $table->index('carried_from_task_id');
            $table->index('carried_to_assessment_id');
        });
    }

    public function down(): void
    {
        Schema::table('odp_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('carried_from_task_id');
            $table->dropConstrainedForeignId('carried_to_assessment_id');
        });
        Schema::dropIfExists('odp_reviews');
    }
};
