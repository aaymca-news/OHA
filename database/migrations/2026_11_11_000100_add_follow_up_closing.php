<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 2, closing the cycle: an assessor or an Administrator closes a signed ODP's
 * follow-up once its three meetings are recorded, its annual review is written and every
 * unfinished task is settled: carried into the next assessment, or dropped with a reason
 * (the dropping is recorded on the task). A closed follow-up is kept as read-only history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->timestampTz('follow_up_closed_at')->nullable()->comment('When the ODP follow-up cycle was closed');
            $table->foreignId('follow_up_closed_by')->nullable()->comment('Who closed it')->constrained('users')->nullOnDelete();
        });

        Schema::table('odp_tasks', function (Blueprint $table) {
            $table->timestampTz('dropped_at')->nullable()->comment('Dropped from the follow-up, with a reason, instead of being carried over');
            $table->foreignId('dropped_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('dropped_reason', 2000)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('odp_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dropped_by');
            $table->dropColumn(['dropped_at', 'dropped_reason']);
        });
        Schema::table('assessments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('follow_up_closed_by');
            $table->dropColumn('follow_up_closed_at');
        });
    }
};
