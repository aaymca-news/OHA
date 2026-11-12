<?php

namespace Database\Seeders;

use App\Actions\Evidence\UploadEvidence;
use App\Actions\Movements\PauseFollowUp;
use App\Actions\Oha\ApproveArtefact;
use App\Actions\Oha\CarryOverTasks;
use App\Actions\Oha\CloseAssessment;
use App\Actions\Oha\CloseFollowUp;
use App\Actions\Oha\FollowUpWindows;
use App\Actions\Oha\NoteOnTask;
use App\Actions\Oha\OpenAssessment;
use App\Actions\Oha\RecordFollowUp;
use App\Actions\Oha\RecordOdpReview;
use App\Actions\Oha\SignAsBoard;
use App\Actions\Oha\SubmitForApproval;
use App\Actions\Oha\UploadForm;
use App\Actions\Oha\UploadOdpVersion;
use App\Actions\Oha\UploadReportVersion;
use App\Demo\DemoForm;
use App\Demo\DemoMovements;
use App\Demo\DemoOdp;
use App\Demo\DemoPeople;
use App\Enums\Role;
use App\Enums\TaskProgress;
use App\Models\Assessment;
use App\Models\Movement;
use App\Models\OdpTask;
use App\Models\User;
use App\Oha\Report\DraftReport;
use App\Oha\Report\WordReport;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * OPTIONAL, for later: a ready-made story for the demo site (demo.oha.africaymca.org).
 * The real National Movement names, with EVERY score, form, report, ODP and follow-up
 * INVENTED (App\Demo\DemoForm and DemoOdp), never a real movement's data. Built through
 * the platform's own actions, so every screen shows what it shows in real use: movements
 * at every stage of Stage 1, and several in Stage 2 (tasks done, waiting, blocked,
 * overdue; follow-up meetings; a review; a pause; tasks carried into a new assessment).
 * Dated back from today.
 *
 * Not run by default (the demo starts empty, for people to upload made-up documents).
 * Run it on top of DemoSeeder: php artisan oha:demo-reset --with-story
 */
class DemoStorySeeder extends Seeder
{
    /**
     * Each movement's story: how strong its invented form is (0–1), how many months ago
     * its assessment was opened, and how far it has gone.
     *
     * @var array<string, array{strength: float, opened: int, to: string, extra?: string}>
     */
    private const STORIES = [
        // Stage 2: the ODP signed, its follow-up under way.
        'ghana' => ['strength' => 0.62, 'opened' => 9, 'to' => 'signed', 'extra' => 'on-track'],
        'kenya' => ['strength' => 0.82, 'opened' => 15, 'to' => 'signed', 'extra' => 'review'],
        'ethiopia' => ['strength' => 0.72, 'opened' => 11, 'to' => 'signed', 'extra' => 'on-track'],
        'togo' => ['strength' => 0.55, 'opened' => 10, 'to' => 'signed', 'extra' => 'behind'],
        'nigeria' => ['strength' => 0.38, 'opened' => 8, 'to' => 'signed', 'extra' => 'behind'],
        'south-africa' => ['strength' => 0.48, 'opened' => 7, 'to' => 'signed', 'extra' => 'paused'],
        'zambia' => ['strength' => 0.5, 'opened' => 20, 'to' => 'signed', 'extra' => 'next-cycle'],
        // Stage 1, at each step.
        'liberia' => ['strength' => 0.58, 'opened' => 4, 'to' => 'odp_approved'],
        'sierra-leone' => ['strength' => 0.66, 'opened' => 4, 'to' => 'odp_submitted'],
        'malawi' => ['strength' => 0.45, 'opened' => 3, 'to' => 'report_submitted'],
        'tanzania' => ['strength' => 0.6, 'opened' => 2, 'to' => 'form_approved'],
        'cameroon' => ['strength' => 0.3, 'opened' => 1, 'to' => 'form_uploaded'],
        'benin' => ['strength' => 0.5, 'opened' => 0, 'to' => 'opened'],
    ];

    private const STEPS = ['opened', 'form_uploaded', 'form_approved', 'report_submitted', 'report_approved', 'odp_submitted', 'odp_approved', 'signed'];

    private Randomizer $random;

    private User $admin;

    public function run(): void
    {
        if (! config('oha.demo') && ! app()->environment('local', 'testing')) {
            throw new RuntimeException('DemoStorySeeder fills the database with invented demo data: it runs only on the demo site (OHA_DEMO=true).');
        }
        $this->random = new Randomizer(new Mt19937(2026));
        // Everything happens at once while seeding: notices are stored straight away.
        config(['queue.default' => 'sync', 'mail.default' => 'log']);

        // The movements and the demo people come first (DemoSeeder; safe to run again).
        $this->call(DemoSeeder::class);
        $this->admin = User::query()->where('email', DemoPeople::STAFF['admin']['email'])->firstOrFail();

        try {
            foreach (self::STORIES as $slug => $story) {
                $this->story(DemoMovements::find($slug) ?? throw new RuntimeException("No movement {$slug}"), $story);
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * @param  array{strength: float, opened: int, to: string, extra?: string}  $story
     */
    private function story(Movement $movement, array $story): void
    {
        $extra = $story['extra'] ?? null;
        // The demo assessor does the work on the movements given to them, so that account shows it all.
        $assessor = $movement->activeAssessors()->where('email', DemoPeople::STAFF['assessor']['email'])->first()
            ?? $movement->activeAssessors()->where('role', Role::Staff)->first()
            ?? $movement->activeAssessors()->firstOrFail();

        // Zambia: an earlier cycle, done, followed up and closed (the next assessment starts only
        // then); then a new assessment, with its unfinished tasks carried over.
        if ($extra === 'next-cycle') {
            $old = $this->assessment($movement, $assessor, $story['strength'], $story['opened'], 'signed');
            $this->followUp($old, $assessor, $story['opened'], null);
            $this->at(now()->subMonths(2), function () use ($old, $assessor): void {
                app(RecordOdpReview::class)->handle($old, $assessor,
                    'The Board was renewed and trained, a reserve fund was opened, and the website is updated every month.',
                    'The property plan was not finished: the title deeds are still with the lands office.',
                    'Set fewer tasks per priority, and agree the evidence for each task at the start.');
                app(CloseFollowUp::class)->handle($old, $assessor, 'The three follow-ups were held; the next assessment is due.');
            });
            $new = $this->assessment($movement, $assessor, min(0.95, $story['strength'] + 0.12), 1, 'form_approved');
            $this->at(now()->subWeeks(2), fn () => app(CarryOverTasks::class)->handle($old, $assessor,
                $old->odpTasks()->where('done', false)->limit(3)->pluck('id')->map(fn ($id) => (int) $id)->all()));

            return;
        }

        $assessment = $this->assessment($movement, $assessor, $story['strength'], $story['opened'], $story['to']);
        if ($story['to'] === 'signed') {
            $this->followUp($assessment, $assessor, $story['opened'], $extra);
        }
    }

    /** One assessment, taken through the real workflow up to $to, its steps dated back from today. */
    private function assessment(Movement $movement, User $assessor, float $strength, int $monthsAgo, string $to): Assessment
    {
        $start = now()->subMonths($monthsAgo)->startOfMonth();
        $day = fn (int $weeks) => $start->copy()->addWeeks($weeks)->setTime(10, 0);
        $reached = fn (string $step) => array_search($step, self::STEPS, true) <= array_search($to, self::STEPS, true);
        $chair = $movement->chair()->firstOrFail();

        $assessment = $this->at($day(0), fn () => app(OpenAssessment::class)->handle($assessor, $movement, $start->format('M Y'), $start));
        if (! $reached('form_uploaded')) {
            return $assessment;
        }

        $form = DemoForm::make($movement->name, $movement->country, (int) $start->format('Y'), $strength, crc32($movement->slug.$start->format('Ym')));
        try {
            $this->at($day(2), fn () => app(UploadForm::class)->handle($assessment->form()->firstOrFail(), $form, Str::slug($movement->country, ' ').' OHA Form '.$start->format('Y').'.xlsx', $assessor));
        } finally {
            @unlink($form);
        }
        if (! $reached('form_approved')) {
            return $assessment;
        }
        $this->at($day(3), fn () => app(SubmitForApproval::class)->handle($assessment->form()->firstOrFail(), $assessor, acknowledgeGaps: true, reason: 'The movement is sending the last figures.'));
        $this->at($day(4), fn () => app(ApproveArtefact::class)->handle($assessment->form()->firstOrFail(), $this->admin));
        if (! $reached('report_submitted')) {
            return $assessment;
        }

        $report = $this->at($day(6), fn () => app(WordReport::class)->write(app(DraftReport::class)->for($assessment->refresh())['sections']));
        try {
            $this->at($day(6), fn () => app(UploadReportVersion::class)->handle($assessment->report()->firstOrFail(), $report, $movement->name.' OHA Report '.$start->format('F Y').'.docx', $assessor));
        } finally {
            @unlink($report);
        }
        $this->at($day(7), fn () => app(SubmitForApproval::class)->handle($assessment->report()->firstOrFail(), $assessor));
        if (! $reached('report_approved')) {
            return $assessment;
        }
        $this->at($day(8), fn () => app(ApproveArtefact::class)->handle($assessment->report()->firstOrFail(), $this->admin));
        if (! $reached('odp_submitted')) {
            return $assessment;
        }

        $odp = DemoOdp::make($movement->name, $day(12), $this->weakest($assessment), crc32($movement->slug.'odp'.$start->format('Ym')));
        try {
            // A made-up Google Drive link (the ODP needs one); the demo never reads Google Drive.
            $drive = 'https://docs.google.com/spreadsheets/d/1Demo'.Str::upper(Str::random(28)).'/edit';
            $this->at($day(10), fn () => app(UploadOdpVersion::class)->handle($assessment->odp()->firstOrFail(), $odp, $movement->name.' ODP '.$start->format('Y').'.xlsx', $assessor, driveUrl: $drive));
        } finally {
            @unlink($odp);
        }
        $this->at($day(11), fn () => app(SubmitForApproval::class)->handle($assessment->odp()->firstOrFail(), $assessor));
        if (! $reached('odp_approved')) {
            return $assessment;
        }
        $this->at($day(12), fn () => app(ApproveArtefact::class)->handle($assessment->odp()->firstOrFail(), $this->admin));
        if (! $reached('signed')) {
            return $assessment;
        }
        $this->at($day(13), fn () => app(SignAsBoard::class)->handle($assessment->odp()->firstOrFail(), $chair, $chair->name, true, 'Approved by the Board at its quarterly meeting.'));
        // Closing the assessment ends Stage 1 (Baseline 1); the signature itself is optional.
        $this->at($day(13), fn () => app(CloseAssessment::class)->handle($assessment, $this->admin));

        return $assessment->refresh();
    }

    /**
     * Stage 2 after closing: progress on the tasks, the follow-up meetings held so far, and
     * the story's twist: on track, falling behind, paused, or the annual review written.
     */
    private function followUp(Assessment $assessment, User $assessor, int $monthsAgo, ?string $extra): void
    {
        $ngs = $assessment->movement->generalSecretary()->firstOrFail();
        $behind = $extra === 'behind';

        // What becomes of each task over the follow-ups: done at one of them (fewer when
        // falling behind), blocked, in progress, or not started yet.
        $fates = [];
        foreach ($assessment->odpTasks()->get() as $task) {
            /** @var OdpTask $task */
            $roll = $this->random->getFloat(0, 1);
            $fates[$task->id] = match (true) {
                $roll < ($behind ? 0.3 : 0.55) => ['done', $this->random->getInt(1, 3)],
                $roll < 0.68 => ['blocked', 0],
                $roll < 0.9 => ['progress', 0],
                default => ['none', 0],
            };
        }
        $statusAt = fn (int $n) => collect($fates)->map(fn (array $fate) => match (true) {
            $fate[0] === 'done' && $fate[1] <= $n => TaskProgress::Done->value,
            $fate[0] === 'blocked' => TaskProgress::Blocked->value,
            $fate[0] === 'progress', $fate[0] === 'done' => TaskProgress::InProgress->value,
            default => TaskProgress::NotStarted->value,
        })->all();

        // The follow-ups, as the assessor agreed them with the movement: every four months. Each
        // is dated, opened two weeks before (the General Secretary then adds notes and evidence),
        // held (where every task stands is set there) and closed, which dates the next. Those not
        // due yet are only dated; when falling behind, the last one due was opened but not held.
        $windows = app(FollowUpWindows::class);
        $closedOn = $assessment->refresh()->closed_at->copy()->startOfDay();
        $dueOf = fn (int $n) => $closedOn->copy()->addMonthsNoOverflow(4 * $n);
        $this->at($closedOn->copy()->addHours(12), fn () => $windows->plan($assessment, $assessor, 1, $dueOf(1)->toDateString()));
        foreach ([1, 2, 3] as $n) {
            $opens = $dueOf($n)->subWeeks(2);
            if ($opens->isFuture()) {
                break;
            }
            $this->at($opens, fn () => $windows->open($assessment, $assessor));
            $held = $dueOf($n)->addDays($this->random->getInt(0, 10));
            if ($held->isFuture() || ($behind && $dueOf($n)->gt(now()->subMonths(4)))) {
                // Open now: the General Secretary has noted one task this week.
                $this->noteOn($assessment, $ngs, $fates, $n, now()->subDays(2));
                break;
            }
            $this->noteOn($assessment, $ngs, $fates, $n, $held->copy()->subDays(5));
            $this->at($held, fn () => app(RecordFollowUp::class)->handle($assessment, $assessor, $n, $held->toDateString(),
                $ngs->name.', '.$assessor->name.', the Board Treasurer',
                'Went through each priority. Financial tasks are moving; governance needs the Board to meet sooner. Agreed to send the evidence before the next meeting.',
                $assessment->odpTasks()->limit(4)->get()->mapWithKeys(fn (OdpTask $t) => [$t->id => 'Discussed; next step agreed.'])->all(),
                $statusAt($n)));
            // Closed after its meeting, which locks it, dating the next one.
            $this->at($held, fn () => $windows->close($assessment, $assessor, $n, $n < 3 ? $dueOf($n + 1)->toDateString() : null));
        }

        match ($extra) {
            'paused' => $this->at(now()->subWeeks(3), fn () => app(PauseFollowUp::class)->pause($assessment->movement, $this->admin,
                now()->addMonths(2)->toDateString(), 'National elections: the Board has agreed to resume after the new Board is seated.')),
            'review' => $this->at(now()->subWeeks(1), fn () => app(RecordOdpReview::class)->handle($assessment, $assessor,
                'The Board was renewed and trained, a reserve fund was opened, and the website is updated every month.',
                'The property plan was not finished: the title deeds are still with the lands office.',
                'Set fewer tasks per priority, and agree the evidence for each task at the start.')),
            default => null,
        };
    }

    /**
     * Before follow-up $n is held, the General Secretary's notes: the evidence of each task
     * done by then (into the library, linked to it), and why a blocked task is stuck.
     *
     * @param  array<int, array{0: string, 1: int}>  $fates
     */
    private function noteOn(Assessment $assessment, User $ngs, array $fates, int $n, Carbon $when): void
    {
        foreach ($assessment->odpTasks()->get() as $task) {
            /** @var OdpTask $task */
            [$fate, $at] = $fates[$task->id] ?? ['none', 0];
            if ($fate === 'done' && $at === $n) {
                $this->at($when, function () use ($task, $ngs, $assessment): void {
                    $evidence = app(UploadEvidence::class)->handle($ngs, $assessment, null, null, Str::limit($task->task, 60, ''),
                        url: 'https://example.org/evidence/'.Str::slug(Str::limit($task->task, 40, '')).'-'.$task->id);
                    app(NoteOnTask::class)->handle($task, $ngs, 'Done; the evidence is in the library.', $evidence->id);
                });
            } elseif ($fate === 'blocked' && $n === 1) {
                $this->at($when, fn () => app(NoteOnTask::class)->handle($task, $ngs, 'Waiting for the funds to be released.'));
            }
        }
    }

    /**
     * The categories where this assessment scored lowest: the ODP's focus.
     *
     * @return list<string>
     */
    private function weakest(Assessment $assessment): array
    {
        return $assessment->categoryScores()->with('category')->get()
            ->sortBy(fn ($s) => (float) $s->points / max(1, (int) $s->max_points_at_scoring))
            ->take(3)->map(fn ($s) => $s->category->code)->values()->all();
    }

    /**
     * Runs $do as if it were $when (every timestamp the actions write is that day).
     *
     * @template T
     *
     * @param  callable(): T  $do
     * @return T
     */
    private function at(Carbon $when, callable $do): mixed
    {
        Carbon::setTestNow($when);
        try {
            return $do();
        } finally {
            Carbon::setTestNow();
        }
    }
}
