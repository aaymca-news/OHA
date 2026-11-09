<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The report drafted by the platform from the OHA form (9 Nov 2026): one draft per
 * report, its sections kept as they are corrected on screen, each with who saved it
 * last. Once made into a Word file, it is a report version like any other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artefact_id')->unique()->comment('The report it drafts')->constrained()->cascadeOnDelete();
            $table->foreignId('form_upload_id')->nullable()->comment('The OHA form upload its facts were taken from')->constrained()->nullOnDelete();
            $table->jsonb('sections')->comment('In order: key, title, kind, the text or points, the form questions behind it, who saved it last');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_drafts');
    }
};
