<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two changes (30 Oct 2026):
 *
 * - A National Movement gets a second user, its National General Secretary: one per
 *   movement, who sees their own movement and supplies its supporting evidence.
 * - Each assessment gets an evidence library: documents that back the form's answers
 *   and the ODP's tasks, each linked to the questions and tasks it supports. A document
 *   can be shared with the other movements. The ODP's reference files move into it.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- The National General Secretary ----------------------------------------
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_role_valid');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_valid CHECK (role IN ('super_admin', 'admin', 'staff', 'board', 'ngs'))");
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_board_has_movement');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_movement_role_has_movement CHECK ((role IN ('board', 'ngs')) = (movement_id IS NOT NULL))");
        DB::statement("CREATE UNIQUE INDEX users_one_ngs_per_movement ON users (movement_id) WHERE role = 'ngs' AND active");

        // --- The evidence library ----------------------------------------------------
        Schema::create('evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->comment('NULL: General, not one OHA category')->constrained()->restrictOnDelete();
            $table->string('title', 200);
            $table->string('format', 10);
            $table->string('disk', 20);
            $table->string('path');
            $table->string('original_name');
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('uploaded_at')->useCurrent();
            $table->timestampTz('shared_at')->nullable()->comment('Set: every movement may see and download it');
            $table->foreignId('shared_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->unique(['assessment_id', 'sha256']);
            $table->index('shared_at');
        });
        DB::statement('ALTER TABLE evidence ADD CONSTRAINT evidence_shared_by_whom CHECK ((shared_at IS NULL) = (shared_by IS NULL))');

        Schema::create('evidence_questions', function (Blueprint $table) {
            $table->foreignId('evidence_id')->constrained('evidence')->cascadeOnDelete();
            $table->string('question_code', 10);
            $table->primary(['evidence_id', 'question_code']);
            $table->index('question_code');
        });

        Schema::create('evidence_odp_tasks', function (Blueprint $table) {
            $table->foreignId('evidence_id')->constrained('evidence')->cascadeOnDelete();
            $table->string('task', 6)->comment('The ODP task number, e.g. 5.2: priority (the category, 1-9) and task');
            $table->primary(['evidence_id', 'task']);
        });
        DB::statement("ALTER TABLE evidence_odp_tasks ADD CONSTRAINT evidence_odp_tasks_number CHECK (task ~ '^[1-9]\\.[1-9][0-9]?$')");

        // --- The ODP's reference files become evidence, under General ---------------
        $references = DB::table('documents')
            ->join('artefacts', 'artefacts.id', '=', 'documents.artefact_id')
            ->where('documents.purpose', 'reference')
            ->orderBy('documents.id')
            ->get(['documents.*', 'artefacts.assessment_id']);

        foreach ($references as $doc) {
            DB::table('evidence')->insertOrIgnore([
                'assessment_id' => $doc->assessment_id,
                'category_id' => null,
                'title' => pathinfo($doc->original_name, PATHINFO_FILENAME),
                'format' => $doc->format,
                'disk' => $doc->disk,
                'path' => $doc->path,
                'original_name' => $doc->original_name,
                'size_bytes' => $doc->size_bytes,
                'sha256' => $doc->sha256,
                'uploaded_by' => $doc->created_by,
                'uploaded_at' => $doc->created_at,
            ]);
        }
        DB::table('documents')->where('purpose', 'reference')->delete();
        DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_purpose_valid');
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_purpose_valid CHECK (purpose IN ('uploaded', 'after_signing'))");
    }

    /**
     * The evidence library is dropped with its records (the files stay on disk); General
     * evidence that came from the ODP's reference files is not put back.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_purpose_valid');
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_purpose_valid CHECK (purpose IN ('reference', 'uploaded', 'after_signing'))");

        Schema::dropIfExists('evidence_odp_tasks');
        Schema::dropIfExists('evidence_questions');
        Schema::dropIfExists('evidence');

        DB::table('users')->where('role', 'ngs')->delete();
        DB::statement('DROP INDEX users_one_ngs_per_movement');
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_movement_role_has_movement');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_board_has_movement CHECK ((role = 'board') = (movement_id IS NOT NULL))");
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_role_valid');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_valid CHECK (role IN ('super_admin', 'admin', 'staff', 'board'))");
    }
};
