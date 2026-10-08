<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two changes (8 Oct 2026):
 *
 * - An Administrator who does an assessment approves their own work: it needs nobody
 *   else's approval. So the rule that nobody approves what they submitted goes.
 * - Signing the ODP makes the OHA form final as approved: answers typed into the approved
 *   upload since its approval are dropped. So the answers typed in when it was approved
 *   are kept apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE artefacts DROP CONSTRAINT IF EXISTS artefacts_no_self_approval');

        Schema::table('form_uploads', function (Blueprint $table) {
            $table->jsonb('approved_supplied')->nullable()->comment('The answers typed in the platform when an Administrator approved this upload');
        });

        DB::statement('UPDATE form_uploads SET approved_supplied = supplied WHERE approved_at IS NOT NULL');
    }

    public function down(): void
    {
        Schema::table('form_uploads', function (Blueprint $table) {
            $table->dropColumn('approved_supplied');
        });

        DB::statement('ALTER TABLE artefacts ADD CONSTRAINT artefacts_no_self_approval CHECK (approved_by IS NULL OR submitted_by IS NULL OR approved_by <> submitted_by) NOT VALID');
    }
};
