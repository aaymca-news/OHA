<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The three follow-ups of a closed assessment (decided 12 Nov 2026). No fixed dates: the
 * movement's assessors, an Administrator or the Super Administrator agree each one's date
 * with the movement and can change it until it is closed (it shows as overdue once that
 * date passes, and never closes by itself). Each follow-up is OPENED by one of them (never
 * automatically), held, and CLOSED, which locks it: its meeting record, and the evidence
 * counted in it, are kept as they are (Baselines 2, 3, 4). Only an Administrator reopens a
 * locked follow-up, with a reason. A row exists once something happened to it.
 *
 * Every piece of evidence records what it counts in: the assessment itself (Baseline 1,
 * NULL), a follow-up (1-3), or, added after the last follow-up, the next assessment (4).
 * What lock follows from that: evidence counted in something locked is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follow_up_windows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number')->comment('1, 2, 3: the follow-up');
            $table->date('due_on')->nullable()->comment('The date agreed with the movement; NULL: not set yet');
            $table->foreignId('due_set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('opened_at')->nullable()->comment('Opened by its assessors or an Administrator: the movement then adds its evidence');
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('locked_at')->nullable()->comment('Closed and locked: what it counted is kept');
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reopened_at')->nullable()->comment('Last reopened by an Administrator');
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reopen_reason', 2000)->nullable();
            $table->timestampsTz();
            $table->unique(['assessment_id', 'number']);
        });
        DB::statement('ALTER TABLE follow_up_windows ADD CONSTRAINT follow_up_windows_number CHECK (number BETWEEN 1 AND 3)');

        Schema::table('evidence', function (Blueprint $table) {
            $table->unsignedSmallInteger('counted_in')->nullable()
                ->comment('NULL: the assessment (Baseline 1); 1-3: that follow-up; 4: the next assessment');
        });
        DB::statement('ALTER TABLE evidence ADD CONSTRAINT evidence_counted_in CHECK (counted_in IS NULL OR counted_in BETWEEN 1 AND 4)');
        // Evidence added since an assessment was closed waits for its first follow-up.
        DB::statement(<<<'SQL'
            UPDATE evidence e SET counted_in = 1
            FROM assessments a
            WHERE a.id = e.assessment_id AND a.closed_at IS NOT NULL AND e.uploaded_at > a.closed_at
            SQL);
    }

    public function down(): void
    {
        Schema::table('evidence', function (Blueprint $table) {
            $table->dropColumn('counted_in');
        });
        Schema::dropIfExists('follow_up_windows');
    }
};
