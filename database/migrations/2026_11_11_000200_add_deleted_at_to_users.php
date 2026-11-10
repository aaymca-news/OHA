<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deleting an account (after deactivating it): it leaves Users & Roles, its email is freed
 * and its sign-in cleared, but the row stays, so the audit trail, approvals and signatures
 * keep the person's name. The database refuses to delete a user the history refers to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestampTz('deleted_at')->nullable()->comment('Account deleted; the row stays for the audit trail');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('deleted_at');
        });
    }
};
