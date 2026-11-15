<?php

namespace App\Console\Commands;

use App\Actions\Oha\ApproveArtefact;
use App\Actions\Oha\CloseAssessment;
use App\Actions\Oha\CloseFollowUp;
use App\Actions\Oha\FollowUpWindows;
use App\Actions\Oha\OpenAssessment;
use App\Actions\Oha\RecordFollowUp;
use App\Actions\Oha\RecordOdpReview;
use App\Actions\Oha\Rescore;
use App\Actions\Oha\SubmitForApproval;
use App\Actions\Oha\UploadForm;
use App\Actions\Oha\UploadOdpVersion;
use App\Actions\Oha\UploadReportVersion;
use App\Demo\DemoMovements;
use App\Demo\DemoPeople;
use App\Demo\DemoSite;
use App\Enums\TaskProgress;
use App\Models\Assessment;
use App\Models\Movement;
use App\Models\User;
use App\Support\Navigation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The demo site only: takes 11 of the Sample YMCAs through the real workflow with the files
 * of the DUMMY DATA folder (a "Sample YMCA N" folder each: the OHA form, its follow-up forms,
 * the report and the ODP), dated back, and leaves them spread over the cycle:
 *   done  - assessment closed (the baseline), follow-ups 1-3 re-scored and closed, cycle closed
 *   fu2   - follow-up 1 re-scored; follow-up 2 open, waiting for its re-upload
 *   fu1   - follow-up 1 open, waiting for its re-upload
 *   first - assessment closed, follow-up 1 dated, not open yet
 * No evidence is added, and no email is sent. The dates are set as if it ran on AS_IF and
 * move with the day it runs, so the open follow-ups are always due in the next few weeks.
 * Run oha:demo-fresh-start first: it refuses movements that already have an assessment.
 */
#[Signature('oha:demo-sample-data {folder : The DUMMY DATA folder, with a "Sample YMCA N" folder per movement} {--force : Run without asking}')]
#[Description('Demo site only: take 11 Sample YMCAs through the workflow with the DUMMY DATA files, spread over the cycle')]
class DemoSampleData extends Command
{
    /** The day the dates below are written for; they move by as many days as today is after it. */
    private const AS_IF = '2026-11-14';

    /**
     * Sample YMCA number => [where it stands, opened, closed, follow-up dates, cycle closed].
     * A follow-up date is its due date; for the one left open, [due, opened].
     *
     * @var array<int, array{0: string, 1: string, 2: string, 3: list<string|array{0: string, 1: string}>, 4?: string}>
     */
    private const PLANS = [
        1 => ['done', '2025-07-07', '2025-09-29', ['2026-01-26', '2026-05-25', '2026-09-21'], '2026-10-08'],
        2 => ['done', '2025-07-14', '2025-10-06', ['2026-02-02', '2026-06-01', '2026-09-28'], '2026-10-15'],
        4 => ['done', '2025-07-21', '2025-10-13', ['2026-02-09', '2026-06-08', '2026-10-05'], '2026-10-22'],
        9 => ['fu2', '2025-07-28', '2025-12-08', ['2026-04-20', ['2026-11-23', '2026-11-09']]],
        7 => ['fu2', '2025-08-11', '2026-01-12', ['2026-05-18', ['2026-11-26', '2026-11-10']]],
        3 => ['fu2', '2025-11-03', '2026-02-09', ['2026-06-15', ['2026-11-30', '2026-11-12']]],
        6 => ['fu1', '2025-11-10', '2026-04-13', [['2026-11-20', '2026-11-06']]],
        10 => ['fu1', '2026-02-09', '2026-05-11', [['2026-11-24', '2026-11-10']]],
        8 => ['fu1', '2026-03-23', '2026-06-22', [['2026-11-27', '2026-11-13']]],
        11 => ['first', '2026-02-16', '2026-10-12', ['2027-02-15']],
        5 => ['first', '2026-04-13', '2026-10-26', ['2027-03-01']],
    ];

    /** Days the plan's dates move by: from AS_IF to today. */
    private int $shift = 0;

    public function handle(): int
    {
        if (($refusal = DemoSite::refusal()) !== null) {
            $this->error($refusal);

            return self::FAILURE;
        }
        $folder = rtrim(str_replace('\\', '/', (string) $this->argument('folder')), '/');
        if (($problem = $this->problem($folder)) !== null) {
            $this->error($problem.' Nothing was changed.');

            return self::FAILURE;
        }
        $this->line('This takes '.count(self::PLANS).' Sample YMCAs through the assessment and its follow-ups with the files in '.$folder.', dated back: 3 with the cycle finished, 3 at follow-up 2, 3 at follow-up 1 and 2 just assessed. No evidence, no email.');
        if (! $this->option('force') && ! $this->confirm('Go ahead?')) {
            return self::SUCCESS;
        }

        // Nobody is emailed about work that is only being set up; anything queued runs now.
        config(['queue.default' => 'sync', 'mail.default' => 'log']);
        $this->shift = (int) Carbon::parse(self::AS_IF)->diffInDays(today(), false);
        $admin = User::query()->where('email', DemoPeople::STAFF['admin']['email'])->firstOrFail();

        foreach (self::PLANS as $number => $plan) {
            $movement = Movement::query()->where('slug', DemoMovements::slug($number))->firstOrFail();
            $assessment = $this->takeThrough($movement, $plan, $folder.'/'.$movement->name, $admin);
            $this->line(sprintf('%-15s %-5s %s', $movement->name, $plan[0], $this->scores($assessment)));
        }
        Navigation::forget();
        $this->info('Done: '.count(self::PLANS).' movements assessed, spread over the cycle.');

        return self::SUCCESS;
    }

    /** What stops the run before anything is changed, or null when all is ready. */
    private function problem(string $folder): ?string
    {
        if (! is_dir($folder)) {
            return "No folder at {$folder}.";
        }
        foreach (self::PLANS as $number => $plan) {
            $movement = Movement::query()->where('slug', DemoMovements::slug($number))->first();
            if ($movement === null) {
                return 'The demo has no '.DemoMovements::name($number).'. Run oha:demo-fresh-start first.';
            }
            if ($movement->assessments()->exists()) {
                return "{$movement->name} already has an assessment. Run oha:demo-fresh-start first.";
            }
            if (! $movement->activeAssessors()->where('email', DemoPeople::STAFF['assessor']['email'])->exists() || ! $movement->generalSecretary()->exists()) {
                return "{$movement->name} has no demo assessor or no National General Secretary.";
            }
            $files = $this->files($folder.'/'.$movement->name, $movement->name, $plan[0] === 'done' ? 3 : ($plan[0] === 'fu2' ? 1 : 0));
            if (is_string($files)) {
                return $files;
            }
        }

        return null;
    }

    /**
     * The movement's files: its form, report, ODP and the follow-up forms it is re-scored with.
     *
     * @return array{form: string, report: string, odp: string, follow_ups: array<int, string>}|string the files, or what is missing
     */
    private function files(string $dir, string $name, int $rescored): array|string
    {
        $missing = "{$name} is missing a file in {$dir} (the OHA form, report, ODP".($rescored > 0 ? ' or a follow-up form' : '').').';
        $one = fn (string $pattern): ?string => glob($dir.'/'.$pattern)[0] ?? null;
        $form = $one('*Annual Organisational Health Assessment Form.xlsx');
        $report = $one('*.docx');
        $odp = $one('*Organisational Development Plan*.xlsx');
        if ($form === null || $report === null || $odp === null) {
            return $missing;
        }
        $followUps = [];
        for ($n = 1; $n <= $rescored; $n++) {
            $followUps[$n] = $one($name.' Annual Organisational Health Assessment Form - Follow-up '.$n.'.xlsx') ?? '';
            if ($followUps[$n] === '') {
                return $missing;
            }
        }

        return ['form' => $form, 'report' => $report, 'odp' => $odp, 'follow_ups' => $followUps];
    }

    /**
     * Stage 1 with its steps spread between opening and closing, then the follow-ups as planned.
     *
     * @param  array{0: string, 1: string, 2: string, 3: list<string|array{0: string, 1: string}>, 4?: string}  $plan
     */
    private function takeThrough(Movement $movement, array $plan, string $dir, User $admin): Assessment
    {
        [$stage, $opened, $closed, $followUps] = $plan;
        $assessor = $movement->activeAssessors()->where('email', DemoPeople::STAFF['assessor']['email'])->firstOrFail();
        $ngs = $movement->generalSecretary()->firstOrFail();
        $files = $this->files($dir, $movement->name, $stage === 'done' ? 3 : ($stage === 'fu2' ? 1 : 0));
        if (is_string($files)) {
            throw new \RuntimeException($files);
        }

        $o = $this->day($opened);
        $c = $this->day($closed);
        $step = fn (float $f) => $o->copy()->addDays((int) round($o->diffInDays($c) * $f));
        $start = $o->copy()->startOfMonth();
        $a = $this->at($o, fn () => app(OpenAssessment::class)->handle($assessor, $movement, $start->format('M Y'), $start));
        $this->at($step(0.10), fn () => app(UploadForm::class)->handle($a->form()->firstOrFail(), $this->copy($files['form']), basename($files['form']), $assessor));
        $this->at($step(0.18), fn () => app(SubmitForApproval::class)->handle($a->form()->firstOrFail(), $assessor, acknowledgeGaps: true, reason: 'The movement is sending the last details.'));
        $this->at($step(0.25), fn () => app(ApproveArtefact::class)->handle($a->form()->firstOrFail(), $admin, acknowledgeGaps: true));
        $this->at($step(0.40), fn () => app(UploadReportVersion::class)->handle($a->report()->firstOrFail(), $this->copy($files['report']), basename($files['report']), $assessor));
        $this->at($step(0.48), fn () => app(SubmitForApproval::class)->handle($a->report()->firstOrFail(), $assessor, acknowledgeGaps: true, reason: 'Agreed with the movement.'));
        $this->at($step(0.55), fn () => app(ApproveArtefact::class)->handle($a->report()->firstOrFail(), $admin, acknowledgeGaps: true));
        $drive = 'https://docs.google.com/spreadsheets/d/1Demo'.Str::upper(Str::random(28)).'/edit';
        $this->at($step(0.70), fn () => app(UploadOdpVersion::class)->handle($a->odp()->firstOrFail(), $this->copy($files['odp']), basename($files['odp']), $assessor, driveUrl: $drive));
        $this->at($step(0.80), fn () => app(SubmitForApproval::class)->handle($a->odp()->firstOrFail(), $assessor, acknowledgeGaps: true, reason: 'Agreed with the movement.'));
        $this->at($step(0.90), fn () => app(ApproveArtefact::class)->handle($a->odp()->firstOrFail(), $admin, acknowledgeGaps: true));
        $firstDue = $this->day(is_array($followUps[0]) ? $followUps[0][0] : $followUps[0])->toDateString();
        $this->at($c, fn () => app(CloseAssessment::class)->handle($a, $assessor, $firstDue));

        // Each follow-up opened two weeks before its date, re-scored from that follow-up's form,
        // its meeting recorded and closed; the last one of "fu1"/"fu2" is left open, waiting.
        $windows = app(FollowUpWindows::class);
        $tasks = $a->odpTasks()->orderBy('id')->pluck('id')->all();
        mt_srand(crc32($movement->name));
        shuffle($tasks);
        foreach ($stage === 'first' ? [] : $followUps as $i => $when) {
            $number = $i + 1;
            if (is_array($when)) {
                $this->at($this->day($when[1]), fn () => $windows->open($a, $assessor));
                break;
            }
            $due = $this->day($when);
            $this->at($due->copy()->subDays(14), fn () => $windows->open($a, $assessor));
            $form = $files['follow_ups'][$number];
            $this->at($due->copy()->subDays(5), fn () => app(Rescore::class)->upload($a, $assessor, $this->copy($form), basename($form), 'The movement’s updated form for follow-up '.$number.'.'));
            $this->at($due, fn () => app(RecordFollowUp::class)->handle($a, $assessor, $number, $due->toDateString(),
                $ngs->name.', '.$assessor->name.', the Board Treasurer',
                'Went through each priority and the re-scored form. Progress agreed on the tasks done; the next steps set for those under way.',
                array_fill_keys(array_slice($tasks, (int) round(count($tasks) * 0.25 * ($number - 1)), 3), 'Discussed; next step agreed.'),
                $this->statuses($tasks, $number)));
            $next = $followUps[$i + 1] ?? null;
            $nextDue = $next === null ? null : $this->day(is_array($next) ? $next[0] : $next)->toDateString();
            $this->at($due, fn () => $windows->close($a, $assessor, $number, $nextDue));
        }
        if ($stage === 'done' && isset($plan[4])) {
            $this->at($this->day($plan[4]), function () use ($a, $assessor): void {
                app(RecordOdpReview::class)->handle($a, $assessor,
                    'Most of the ODP was carried out: the Board policies were adopted, the finances broadened and a reserve built.',
                    'A few tasks are still under way and go into the next assessment.',
                    'Agree the evidence for each task at the start, and hold the follow-ups on their dates.');
                app(CloseFollowUp::class)->handle($a, $assessor);
            });
        }

        return $a->refresh();
    }

    /**
     * Where the tasks stand at a follow-up: a quarter more done each time, the next few under
     * way, the last one stuck until follow-up 3.
     *
     * @param  list<int>  $tasks
     * @return array<int, string>
     */
    private function statuses(array $tasks, int $number): array
    {
        $count = count($tasks);
        $statuses = [];
        foreach ($tasks as $k => $id) {
            $statuses[$id] = match (true) {
                $k < (int) round($count * 0.25 * $number) => TaskProgress::Done->value,
                $k < (int) round($count * (0.25 * $number + 0.3)) => TaskProgress::InProgress->value,
                $k === $count - 1 && $number < 3 => TaskProgress::Blocked->value,
                default => TaskProgress::NotStarted->value,
            };
        }

        return $statuses;
    }

    /** A plan date, moved to match the day this runs. */
    private function day(string $date): Carbon
    {
        return Carbon::parse($date)->addDays($this->shift);
    }

    /**
     * Does the step as if it were that day (10:00), so everything it records carries that date.
     *
     * @template T
     *
     * @param  callable(): T  $do
     * @return T
     */
    private function at(Carbon $when, callable $do): mixed
    {
        Carbon::setTestNow($when->copy()->setTime(10, 0));
        try {
            return $do();
        } finally {
            Carbon::setTestNow();
        }
    }

    /** A copy of a DUMMY DATA file to hand to an action, which keeps it: the originals are never touched. */
    private function copy(string $file): string
    {
        $name = (string) tempnam(sys_get_temp_dir(), 'oha');
        @unlink($name);
        $tmp = $name.'.'.pathinfo($file, PATHINFO_EXTENSION);
        copy($file, $tmp);

        return $tmp;
    }

    /** The baselines it ended with, e.g. "B1 52/120 (43.3%) → B2 …". */
    private function scores(Assessment $assessment): string
    {
        return $assessment->baselines()->whereNull('superseded_at')->orderBy('number')->get()
            ->map(fn ($b) => 'B'.$b->number.' '.round((float) $b->total?->points_achieved).'/'.$b->total?->points_available.' ('.$b->total?->pct.'%)')
            ->implode(' → ').($assessment->follow_up_closed_at ? '  (cycle closed)' : '');
    }
}
