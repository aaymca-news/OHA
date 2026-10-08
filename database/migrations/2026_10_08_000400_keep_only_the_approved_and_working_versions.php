<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 1 keeps only what is needed (8 Oct 2026): of the OHA form, the report and the ODP,
 * the last approved version and the version being worked on. Versions in between are
 * removed as new ones come, to save space; the audit trail keeps the history. So each
 * version keeps the number it was given: version 3 stays "version 3" after 2 is removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedSmallInteger('version_number')->nullable()->comment('Uploaded versions: 1, 2, 3… in the order saved; kept when earlier ones are removed');
        });

        DB::statement(<<<'SQL'
            UPDATE documents d SET version_number = n.number
            FROM (SELECT id, ROW_NUMBER() OVER (PARTITION BY artefact_id ORDER BY id) AS number
                  FROM documents WHERE purpose = 'uploaded') n
            WHERE n.id = d.id
            SQL);
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('version_number');
        });
    }
};
