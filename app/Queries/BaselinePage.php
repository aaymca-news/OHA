<?php

namespace App\Queries;

use App\Models\Assessment;
use App\Models\Baseline;
use App\Models\Category;
use App\Models\FormUpload;
use App\Models\HealthBand;
use App\Models\RescoreUpload;
use App\Oha\CategoryRows;
use App\Oha\FollowUpSchedule;
use App\Oha\Rescoring;
use App\Oha\ScoreGaps;
use App\Oha\Scorer;
use Illuminate\Support\Collection;

/**
 * What the Scores and baselines page shows (decided 12 Nov 2026): every baseline (in
 * force and set aside), the one viewed, and, while a follow-up is open, the answers being
 * re-scored: their score now (by the form's own formulas), each point still open with
 * what earns it, what changed since the starting baseline and why, and the "Yes" answers
 * with no evidence counted yet.
 */
final class BaselinePage
{
    public function __construct(
        private readonly Scorer $scorer,
        private readonly Journeys $journeys,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Assessment $assessment, ?int $viewing): array
    {
        /** @var Collection<int, Baseline> $baselines */
        $baselines = $assessment->baselines()->with(['total.band', 'setter', 'superseder'])->get();
        $inForce = $baselines->filter(fn (Baseline $b) => ! $b->isSuperseded())->sortByDesc('number')->first();
        $viewed = ($viewing !== null ? $baselines->firstWhere('id', $viewing) : null) ?? $inForce;

        $next = FollowUpSchedule::next($assessment);
        $openFollowUp = $next !== null && $next['state'] === FollowUpSchedule::OPEN && ! $assessment->isFollowUpClosed() ? $next['number'] : null;

        return [
            'baselines' => $baselines,
            'journey' => $this->journeys->of($assessment),
            'inForce' => $inForce,
            'viewed' => $viewed,
            'viewedRows' => $viewed !== null ? CategoryRows::for($assessment, $viewed) : [],
            'openFollowUp' => $openFollowUp,
            'working' => $openFollowUp !== null ? $this->working($assessment, $openFollowUp) : null,
            'labels' => $this->labels($baselines),
            'categoryNames' => Category::query()->orderBy('form_order')->pluck('name', 'code')->all(),
        ];
    }

    /**
     * The answers being re-scored in follow-up $followUp, and where they stand.
     *
     * @return array<string, mixed>|null
     */
    private function working(Assessment $assessment, int $followUp): ?array
    {
        $start = Rescoring::startingPoint($assessment, $followUp);
        if ($start === null) {
            return null;
        }
        $answers = Rescoring::answers($assessment, $followUp, $start);
        $window = Rescoring::window($assessment, $followUp);
        $scored = $this->scorer->score($answers);

        $rows = [];
        foreach (Category::query()->weighted()->orderBy('form_order')->get() as $category) {
            $rows[$category->code] = ['name' => $category->name, 'points' => (int) ($scored[$category->code] ?? 0), 'max' => (int) $category->max_points];
        }
        $points = array_sum(array_column($rows, 'points'));
        $max = array_sum(array_column($rows, 'max'));
        $pct = $max > 0 ? round($points * 100 / $max, 1) : 0.0;

        $gaps = ScoreGaps::for($answers);
        $open = array_column(array_merge(...array_values($gaps)), 'code');
        $earned = array_map(fn (array $items) => array_values(array_filter($items, fn (array $i) => ! in_array($i['code'], $open, true))), ScoreGaps::scored());

        return [
            'followUp' => $followUp,
            'number' => $followUp + 1,
            'start' => $start,
            'answers' => $answers,
            'startAnswers' => $start->answers,
            'changes' => Rescoring::changes($window?->answer_changes, $start->answers, $answers),
            'rows' => $rows,
            'points' => $points,
            'max' => $max,
            'pct' => $pct,
            'band' => HealthBand::forPercentage($pct),
            'gaps' => $gaps,
            'pointsOpen' => ScoreGaps::pointsOpen($gaps),
            'earned' => $earned,
            'evidenceGaps' => Rescoring::evidenceGaps($assessment, $answers, $followUp),
            'uploads' => RescoreUpload::query()->where('assessment_id', $assessment->id)->where('follow_up_number', $followUp)
                ->with('uploader')->latest('id')->get(),
        ];
    }

    /**
     * Each question's wording, as the approved form gave it.
     *
     * @param  Collection<int, Baseline>  $baselines
     * @return array<string, string>
     */
    private function labels(Collection $baselines): array
    {
        $uploadId = $baselines->firstWhere('number', 1)?->form_upload_id;
        $upload = $uploadId !== null ? FormUpload::query()->find($uploadId) : null;

        return array_map('strval', $upload->form_meta['labels'] ?? []);
    }
}
