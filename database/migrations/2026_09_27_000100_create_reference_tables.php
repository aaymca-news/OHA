<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reference data: the numbers the OHA model runs on.
 *
 * These rows are the single source for weights, bands, deadlines and cadence.
 * No PHP code may hard-code any of them. Changing a value is a new migration
 * (or, later, an audited admin action), never an edit to application code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zones', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 60);
            $table->unsignedSmallInteger('sort_order');
        });

        Schema::create('membership_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('label', 40);
            $table->string('icon', 40);
            $table->unsignedSmallInteger('sort_order');
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 80);
            $table->string('short_name', 30);
            $table->string('icon', 40);
            $table->unsignedSmallInteger('form_order')->unique();
            $table->unsignedSmallInteger('max_points')->nullable()->comment('NULL = not yet weighted; excluded from the total');
        });

        Schema::create('health_bands', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('label', 40);
            $table->decimal('min_pct', 5, 2)->unique()->comment('A score belongs to the band with the highest min_pct not above it');
            $table->string('color', 7);
            $table->string('icon', 40);
            $table->unsignedSmallInteger('reassess_months');
            $table->unsignedSmallInteger('sort_order');
        });

        Schema::create('gates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 60);
            $table->string('milestone_label', 60);
            $table->unsignedSmallInteger('sort_order')->unique();
            $table->unsignedSmallInteger('sla_days')->comment('Days from the assessment opening by which this gate should be cleared');
        });

        Schema::create('turnaround_rules', function (Blueprint $table) {
            $table->id();
            $table->string('holder_role', 20)->unique();
            $table->unsignedSmallInteger('days')->comment('Days the current holder has to act once work reaches them');
        });

        DB::statement('ALTER TABLE categories ADD CONSTRAINT categories_max_points_positive CHECK (max_points IS NULL OR max_points > 0)');
        DB::statement('ALTER TABLE health_bands ADD CONSTRAINT health_bands_min_pct_range CHECK (min_pct >= 0 AND min_pct <= 100)');
        DB::statement("ALTER TABLE turnaround_rules ADD CONSTRAINT turnaround_rules_holder_role CHECK (holder_role IN ('assessor', 'approver', 'releaser', 'board'))");

        DB::table('zones')->insert([
            ['code' => 'east', 'name' => 'East Africa', 'sort_order' => 1],
            ['code' => 'west', 'name' => 'West Africa', 'sort_order' => 2],
            ['code' => 'central', 'name' => 'Central Africa', 'sort_order' => 3],
            ['code' => 'southern', 'name' => 'Southern Africa', 'sort_order' => 4],
        ]);

        DB::table('membership_statuses')->insert([
            ['code' => 'chartered', 'label' => 'Chartered', 'icon' => 'verified', 'sort_order' => 1],
            ['code' => 'associate', 'label' => 'Associate', 'icon' => 'handshake', 'sort_order' => 2],
            ['code' => 'formation', 'label' => 'In formation', 'icon' => 'construction', 'sort_order' => 3],
        ]);

        // Weights verified against all 15 published movement scores (84 points in total).
        DB::table('categories')->insert([
            ['code' => 'financial', 'name' => 'Financial Stability', 'short_name' => 'Financial', 'icon' => 'payments', 'form_order' => 1, 'max_points' => 20],
            ['code' => 'governance', 'name' => 'Governance', 'short_name' => 'Governance', 'icon' => 'gavel', 'form_order' => 2, 'max_points' => 12],
            ['code' => 'constitution', 'name' => 'Constitution / Bylaws / Policies', 'short_name' => 'Constitution', 'icon' => 'description', 'form_order' => 3, 'max_points' => 20],
            ['code' => 'me', 'name' => 'Monitoring & Evaluation', 'short_name' => 'M&E', 'icon' => 'query_stats', 'form_order' => 4, 'max_points' => 6],
            ['code' => 'strategy', 'name' => 'Strategic Planning', 'short_name' => 'Strategy', 'icon' => 'flag', 'form_order' => 5, 'max_points' => 12],
            ['code' => 'diversity', 'name' => 'Diversity & Youth Participation', 'short_name' => 'Diversity', 'icon' => 'diversity_3', 'form_order' => 6, 'max_points' => 4],
            ['code' => 'comms', 'name' => 'Communications & Branding', 'short_name' => 'Comms', 'icon' => 'campaign', 'form_order' => 7, 'max_points' => 5],
            ['code' => 'property', 'name' => 'Property Management', 'short_name' => 'Property', 'icon' => 'domain', 'form_order' => 8, 'max_points' => null],
            ['code' => 'staff', 'name' => 'Staff & Volunteer Development', 'short_name' => 'Staff', 'icon' => 'groups', 'form_order' => 9, 'max_points' => 5],
        ]);

        DB::table('health_bands')->insert([
            ['code' => 'excellent', 'label' => 'Excellent', 'min_pct' => 90, 'color' => '#0ca30c', 'icon' => 'verified', 'reassess_months' => 36, 'sort_order' => 1],
            ['code' => 'strong', 'label' => 'Strong', 'min_pct' => 70, 'color' => '#0ca30c', 'icon' => 'check_circle', 'reassess_months' => 24, 'sort_order' => 2],
            ['code' => 'developing', 'label' => 'Developing', 'min_pct' => 50, 'color' => '#fab219', 'icon' => 'trending_up', 'reassess_months' => 18, 'sort_order' => 3],
            ['code' => 'atrisk', 'label' => 'At Risk', 'min_pct' => 20, 'color' => '#ec835a', 'icon' => 'warning', 'reassess_months' => 12, 'sort_order' => 4],
            ['code' => 'critical', 'label' => 'Critical', 'min_pct' => 0.01, 'color' => '#d03b3b', 'icon' => 'error', 'reassess_months' => 6, 'sort_order' => 5],
            ['code' => 'notstarted', 'label' => 'Not started', 'min_pct' => 0, 'color' => '#898781', 'icon' => 'remove', 'reassess_months' => 6, 'sort_order' => 6],
        ]);

        DB::table('gates')->insert([
            ['code' => 'form', 'name' => 'OHA form', 'milestone_label' => 'OHA form approved', 'sort_order' => 1, 'sla_days' => 21],
            ['code' => 'report', 'name' => 'Report', 'milestone_label' => 'Report approved', 'sort_order' => 2, 'sla_days' => 42],
            ['code' => 'odp', 'name' => 'ODP', 'milestone_label' => 'ODP approved', 'sort_order' => 3, 'sla_days' => 63],
            ['code' => 'release', 'name' => 'Release to the board', 'milestone_label' => 'ODP released to the board', 'sort_order' => 4, 'sla_days' => 77],
            ['code' => 'board', 'name' => 'Board validation', 'milestone_label' => 'ODP validated by the board', 'sort_order' => 5, 'sla_days' => 98],
        ]);

        DB::table('turnaround_rules')->insert([
            ['holder_role' => 'assessor', 'days' => 14],
            ['holder_role' => 'approver', 'days' => 5],
            ['holder_role' => 'releaser', 'days' => 7],
            ['holder_role' => 'board', 'days' => 21],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('turnaround_rules');
        Schema::dropIfExists('gates');
        Schema::dropIfExists('health_bands');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('membership_statuses');
        Schema::dropIfExists('zones');
    }
};
