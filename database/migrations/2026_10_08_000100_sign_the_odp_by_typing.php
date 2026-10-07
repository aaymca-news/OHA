<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Board Chairperson signs the ODP by typing their initials or full name (8 Oct 2026),
 * instead of drawing. A drawn signature made before stays as it was; other ways of
 * signing may be added later, so each signature records how it was made.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('board_signatures', function (Blueprint $table) {
            $table->string('signature_method', 10)->default('drawn')->comment('drawn: a picture drawn on screen; typed: initials or name typed');
            $table->string('signature_text', 100)->nullable()->comment('Typed: the initials or name the Chairperson typed as their signature');
            $table->string('signature_disk', 20)->nullable()->change();
            $table->string('signature_path')->nullable()->change();
            $table->char('signature_sha256', 64)->nullable()->change();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE board_signatures ADD CONSTRAINT board_signatures_mark_present CHECK (
                (signature_method = 'drawn' AND signature_path IS NOT NULL AND signature_sha256 IS NOT NULL)
                OR (signature_method = 'typed' AND signature_text IS NOT NULL AND btrim(signature_text) <> '')
            )
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE board_signatures DROP CONSTRAINT IF EXISTS board_signatures_mark_present');
        DB::statement("DELETE FROM board_signatures WHERE signature_method = 'typed'");

        Schema::table('board_signatures', function (Blueprint $table) {
            $table->dropColumn(['signature_method', 'signature_text']);
            $table->string('signature_disk', 20)->nullable(false)->change();
            $table->string('signature_path')->nullable(false)->change();
            $table->char('signature_sha256', 64)->nullable(false)->change();
        });
    }
};
