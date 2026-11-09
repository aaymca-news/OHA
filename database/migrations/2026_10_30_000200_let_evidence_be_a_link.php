<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Evidence can be a link instead of a file (30 Oct 2026): a website is proven by its
 * address, not by a document. Each piece of evidence is one or the other.
 */
return new class extends Migration
{
    private const FILE_COLUMNS = ['format', 'disk', 'path', 'original_name', 'size_bytes', 'sha256'];

    public function up(): void
    {
        Schema::table('evidence', function (Blueprint $table) {
            $table->string('url', 2000)->nullable()->after('title')->comment('Set: the evidence is this link, and has no file');
        });
        foreach (self::FILE_COLUMNS as $column) {
            DB::statement("ALTER TABLE evidence ALTER COLUMN {$column} DROP NOT NULL");
        }
        $noFile = implode(' AND ', array_map(fn ($c) => "{$c} IS NULL", self::FILE_COLUMNS));
        $file = implode(' AND ', array_map(fn ($c) => "{$c} IS NOT NULL", self::FILE_COLUMNS));
        DB::statement("ALTER TABLE evidence ADD CONSTRAINT evidence_file_or_link CHECK ((url IS NOT NULL AND {$noFile}) OR (url IS NULL AND {$file}))");
        DB::statement("ALTER TABLE evidence ADD CONSTRAINT evidence_link_is_web CHECK (url IS NULL OR url ~* '^https?://')");
    }

    /** Links go: before this, evidence was always a file. */
    public function down(): void
    {
        DB::table('evidence')->whereNotNull('url')->delete();
        DB::statement('ALTER TABLE evidence DROP CONSTRAINT evidence_link_is_web');
        DB::statement('ALTER TABLE evidence DROP CONSTRAINT evidence_file_or_link');
        foreach (self::FILE_COLUMNS as $column) {
            DB::statement("ALTER TABLE evidence ALTER COLUMN {$column} SET NOT NULL");
        }
        Schema::table('evidence', function (Blueprint $table) {
            $table->dropColumn('url');
        });
    }
};
