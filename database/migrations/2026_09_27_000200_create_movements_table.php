<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movements', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->string('name', 80)->unique();
            $table->string('country', 60);
            $table->string('city', 60);
            $table->foreignId('zone_id')->constrained()->restrictOnDelete();
            $table->foreignId('membership_status_id')->constrained()->restrictOnDelete();
            $table->string('planned_assessment_label', 40)->nullable()->comment('As AAYMCA wrote it, e.g. "Qtr 4 2026" or "TBD"');
            $table->date('planned_assessment_on')->nullable()->comment('First day of the planned period; NULL when not scheduled');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movements');
    }
};
