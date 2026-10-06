<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The movements were first loaded with the assessment quarters of the old risk
 * register ("Qtr 1 2027", "TBD", …). Nobody scheduled those in OHA, and nothing in OHA
 * writes these columns, so they are cleared: every movement shows "Not scheduled"
 * until an assessment is actually planned.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('movements')->update([
            'planned_assessment_label' => null,
            'planned_assessment_on' => null,
        ]);
    }

    public function down(): void
    {
        // The old register's quarters are not restored: they were never a schedule.
    }
};
