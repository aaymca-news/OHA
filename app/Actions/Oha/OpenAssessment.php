<?php

namespace App\Actions\Oha;

use App\Actions\Oha\Concerns\EnforcesPolicy;
use App\Enums\ArtefactKind;
use App\Enums\ArtefactState;
use App\Models\Assessment;
use App\Models\Movement;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Opens an assessment for a movement, with its form, report and ODP not started.
 * Its four gate deadlines follow from the opening date (see v_assessment_milestones).
 */
final class OpenAssessment
{
    use EnforcesPolicy;

    public function handle(User $opener, Movement $movement, string $periodLabel, Carbon $periodStart): Assessment
    {
        $this->ensure($opener, 'openAssessment', $movement);

        return DB::transaction(function () use ($opener, $movement, $periodLabel, $periodStart): Assessment {
            $assessment = Assessment::query()->create([
                'movement_id' => $movement->id,
                'period_label' => $periodLabel,
                'assessed_on' => $periodStart->copy()->startOfMonth()->toDateString(),
                'opened_at' => now(),
                'opened_by' => $opener->id,
            ]);

            foreach (ArtefactKind::cases() as $kind) {
                $assessment->artefacts()->create(['kind' => $kind, 'state' => ArtefactState::NotStarted]);
            }

            Audit::record($opener, 'assessment.opened', $assessment, $assessment, payload: ['period' => $periodLabel]);

            return $assessment;
        });
    }
}
