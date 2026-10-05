<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two changes of 30 Sep 2026:
 *
 * - Two-step verification is removed completely, columns included. It will be
 *   added afresh later.
 * - A Super Administrator may delete an assessment, so a movement shows as not yet
 *   assessed again. The audit trail must outlive what it describes: its link to the
 *   assessment becomes a plain reference (like subject_id), not a foreign key, so
 *   the append-only rows are never touched and still say what happened to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });

        Schema::table('audit_events', function (Blueprint $table) {
            $table->dropForeign(['assessment_id']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table) {
            $table->foreign('assessment_id')->references('id')->on('assessments')->restrictOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->after('password')->nullable();
            $table->text('two_factor_recovery_codes')->after('two_factor_secret')->nullable();
            $table->timestamp('two_factor_confirmed_at')->after('two_factor_recovery_codes')->nullable();
        });
    }
};
