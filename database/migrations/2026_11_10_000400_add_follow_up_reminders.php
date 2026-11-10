<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 2, ODP follow-up, reminders (10 Nov 2026):
 *   - an approved pause on a movement's follow-up (elections, a crisis): until a date,
 *     with a reason and the Administrator who agreed it. Reminders stop meanwhile, and
 *     "Paused" is shown instead of "Overdue";
 *   - the reminders already sent, so none is sent twice and a missed day loses none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movements', function (Blueprint $table) {
            $table->date('follow_up_paused_until')->nullable()->comment('Set: follow-up reminders and overdue flags paused until this day');
            $table->string('follow_up_pause_reason', 1000)->nullable();
            $table->foreignId('follow_up_paused_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('sent_reminders', function (Blueprint $table) {
            $table->id();
            $table->string('key', 190)->unique()->comment('What was sent, to whom or for what, and for which week or date');
            $table->timestampTz('sent_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sent_reminders');
        Schema::table('movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('follow_up_paused_by');
            $table->dropColumn(['follow_up_paused_until', 'follow_up_pause_reason']);
        });
    }
};
