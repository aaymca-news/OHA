<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 2, ODP follow-up (10 Nov 2026): the follow-up meetings, 3 a year as in the ODP
 * template, due 4, 8 and 12 months after the ODP is signed. Each is recorded by an
 * assessor with what was said, and keeps a snapshot of every task as it stood then,
 * with the update given for it at the meeting (the template's follow-up columns).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('odp_follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number')->comment('1, 2, 3: the first, second and third follow-up after signing');
            $table->date('held_on');
            $table->string('attendees', 1000)->nullable();
            $table->text('summary');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['assessment_id', 'number']);
        });

        Schema::create('odp_follow_up_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('odp_follow_up_id')->constrained()->cascadeOnDelete();
            $table->foreignId('odp_task_id')->nullable()->comment('The task, while it still exists; the copy below stays either way')->constrained()->nullOnDelete();
            $table->string('ref', 16)->nullable();
            $table->text('task');
            $table->string('progress', 12)->comment('Where it stood at the meeting');
            $table->boolean('done')->default(false)->comment('Confirmed done at the meeting');
            $table->text('update')->nullable()->comment('What was said about it at the meeting');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->index('odp_follow_up_id');
            $table->index('odp_task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('odp_follow_up_tasks');
        Schema::dropIfExists('odp_follow_ups');
    }
};
