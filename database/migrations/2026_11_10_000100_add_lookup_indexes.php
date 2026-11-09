<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the lookups the platform makes often, ready for more movements and
 * assessments (10 Nov 2026). PostgreSQL does not index a foreign key by itself, so a
 * lookup by it, or a check when a parent row is deleted (old versions are pruned),
 * reads the whole table. Indexes only: no data changes.
 */
return new class extends Migration
{
    /** @var array<string, list<list<string>>> table => the column lists to index */
    private const INDEXES = [
        // The findings of a form upload (the Form tab), and pruning an upload.
        'form_findings' => [['form_upload_id']],
        // The scores frozen from a form upload (movement page, dashboards), and pruning.
        'category_scores' => [['form_upload_id']],
        // The comments on a document.
        'artefact_comments' => [['artefact_id']],
        // An assessment's own timeline steps (only its gate rows were indexed).
        'timeline_items' => [['assessment_id']],
        // Who did what (the audit trail's person filter), and an assessment's events by kind.
        'audit_events' => [['actor_id'], ['assessment_id', 'action']],
        // The movements a person assesses: asked on almost every page.
        'movement_user' => [['user_id']],
        // Whether a stored file is still used, before it is deleted (VersionPruner).
        'documents' => [['disk', 'path']],
        'form_uploads' => [['disk', 'path']],
        // The ODP tasks read from a version, and pruning that version.
        'odp_tasks' => [['document_id']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            Schema::table($table, function (Blueprint $t) use ($indexes) {
                foreach ($indexes as $columns) {
                    $t->index($columns);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            Schema::table($table, function (Blueprint $t) use ($indexes) {
                foreach ($indexes as $columns) {
                    $t->dropIndex($columns);
                }
            });
        }
    }
};
