<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The audit trail is append-only. Revoking UPDATE/DELETE is not enough, because
 * the application's database role owns the table, so a trigger refuses any
 * change to an existing row, from the app, a script or a direct SQL session.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('occurred_at')->useCurrent();
            $table->foreignId('actor_id')->nullable()->comment('NULL = the system')->constrained('users')->restrictOnDelete();
            $table->string('action', 60);
            $table->string('subject_type', 60);
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('assessment_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('from_state', 30)->nullable();
            $table->string('to_state', 30)->nullable();
            $table->foreignId('routed_to')->nullable()->constrained('users')->restrictOnDelete();
            $table->jsonb('payload')->nullable();
            $table->ipAddress('ip')->nullable();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['assessment_id', 'occurred_at']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_events_reject_change() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit_events is append-only: % is not allowed', TG_OP
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER audit_events_no_update_or_delete
                BEFORE UPDATE OR DELETE ON audit_events
                FOR EACH ROW EXECUTE PROCEDURE audit_events_reject_change();

            CREATE TRIGGER audit_events_no_truncate
                BEFORE TRUNCATE ON audit_events
                FOR EACH STATEMENT EXECUTE PROCEDURE audit_events_reject_change();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        DB::unprepared('DROP FUNCTION IF EXISTS audit_events_reject_change()');
    }
};
