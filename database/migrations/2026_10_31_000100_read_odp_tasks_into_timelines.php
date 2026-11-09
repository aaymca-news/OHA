<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ODP's tasks on the Timelines page (31 Oct 2026). Each task and its deadline is
 * read from the ODP workbook; what cannot be read (a Word ODP, a deadline written as
 * "Ongoing") is typed in by the assessors, who may also correct what was read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('odp_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->nullable()->comment('The ODP version it was read from; NULL when typed in')->constrained()->nullOnDelete();
            $table->string('source', 10)->comment('odp: read from the ODP; typed: added by a person');
            $table->string('ref', 16)->nullable()->comment('The ODP’s own number, such as 1.2');
            $table->string('priority')->nullable()->comment('Its strategic priority, such as FINANCIAL STABILITY');
            $table->text('task');
            $table->string('deadline_text', 500)->nullable()->comment('The deadline as the ODP writes it');
            $table->date('due_on')->nullable();
            $table->boolean('date_guessed')->default(false)->comment('The ODP gave no year ("End of June"): the year was taken from its period of implementation');
            $table->string('responsible', 500)->nullable();
            $table->boolean('done')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('edited_at')->nullable()->comment('Set: a person corrected it, so a newer ODP version keeps their date');
            $table->timestampsTz();
            $table->index(['assessment_id', 'sort_order']);
        });

        Schema::table('assessments', function (Blueprint $table) {
            $table->foreignId('odp_read_document_id')->nullable()->comment('The ODP version its tasks were last read from')->constrained('documents')->nullOnDelete();
            $table->string('odp_read_problem', 500)->nullable()->comment('Why the ODP’s tasks could not be read, if they could not');
        });
    }

    public function down(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('odp_read_document_id');
            $table->dropColumn('odp_read_problem');
        });
        Schema::dropIfExists('odp_tasks');
    }
};
