<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closing the cycle (12 Nov 2026): once its three follow-ups are closed, by the movement's
 * assessors or an Administrator; before that, only by an Administrator, who says why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->string('follow_up_closed_reason', 2000)->nullable()->comment('Why the cycle was closed before its three follow-ups (by an Administrator)');
        });
    }

    public function down(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->dropColumn('follow_up_closed_reason');
        });
    }
};
