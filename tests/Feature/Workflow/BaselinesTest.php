<?php

use App\Actions\Oha\FollowUpWindows;
use App\Actions\Oha\Rescore;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Baseline;
use App\Models\Category;
use App\Models\FormUpload;
use App\Models\MovementStatus;
use App\Models\User;
use App\Oha\FormChecker;
use App\Oha\FormReader;
use App\Oha\Rescoring;
use App\Oha\ScoreGaps;
use App\Oha\Scorer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\Journey;

/*
 * Baselines 1–4 (decided 12 Nov 2026): Baseline 1 is the approved form when the assessment
 * is closed; during each open follow-up the answers are re-scored (a re-filled Excel form,
 * or answer by answer on the site) by the form's own formulas; closing the follow-up
 * records the next baseline, which is then the movement's score everywhere. The site and
 * the Excel form never disagree: once every gap is met, the score is 84 of 84 (100%).
 */

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
    $this->ngs = User::factory()->generalSecretary($this->j->zambia)->create();
    $this->assessment = $this->j->assessmentAt('closed');
    $this->rescore = app(Rescore::class);
    $this->score = fn () => DB::table('v_assessment_scores')->where('assessment_id', $this->assessment->id)->first();
});

/** The answer that earns a scored item's points, as typed on the site: [ref, input]. */
function earningInputs(array $item): array
{
    return match ($item['code']) {
        'Q214' => [['g:income', ['Q214' => '40', 'Q215' => '30', 'Q216' => '30']]],
        'Q223' => [['q:Q224', '50']],
        'Q225' => [['q:Q225', '50']],
        'Q241' => [['q:Q241', '12']],
        'Q322' => [['q:Q322', '3']],
        'Q244' => [['q:Q244', 'No'], ['q:Q245', 'No']],
        default => [['q:'.$item['code'], $item['options'][0]]],
    };
}

it('records Baseline 1 at closing: the approved scores exactly, and the answers they came from', function () {
    $baseline = Rescoring::inForce($this->assessment, 1);
    $frozen = $this->assessment->categoryScores()->get()->keyBy('category_id');

    expect($baseline)->not->toBeNull()
        ->and($baseline->follow_up_number)->toBeNull()
        ->and($baseline->form_upload_id)->toBe($frozen->first()->form_upload_id)
        ->and($baseline->answers)->toEqual(FormUpload::query()->find($baseline->form_upload_id)->answers)
        ->and($baseline->scores()->get()->mapWithKeys(fn ($s) => [$s->category_id => (float) $s->points])->all())
        ->toEqual($frozen->map(fn ($s) => (float) $s->points)->all())
        // Scored again by the form's own formulas, the answers give the very same points.
        ->and(array_filter(app(Scorer::class)->score($baseline->answers), fn ($p) => $p !== null))
        ->toEqual(Category::query()->weighted()->get()->mapWithKeys(fn ($c) => [$c->code => (int) $frozen[$c->id]->points])->all())
        ->and(($this->score)()->baseline)->toBe(1);
});

it('lists every point still open, and once each is met on the site the score is 84 of 84 (100%)', function () {
    $start = Rescoring::inForce($this->assessment, 1);
    $startPoints = (float) ($this->score)()->points_achieved;
    $gaps = ScoreGaps::for($start->answers);

    // Every point not earned is listed, nothing more.
    expect(array_sum(ScoreGaps::pointsOpen($gaps)))->toBe(84 - (int) $startPoints)
        ->and(array_sum(array_column(Category::query()->weighted()->get()->toArray(), 'max_points')))->toBe(84);

    $this->j->openFollowUp($this->assessment);
    foreach (array_merge(...array_values($gaps)) as $item) {
        foreach (earningInputs($item) as [$ref, $input]) {
            $this->rescore->change($this->assessment, $this->j->assessor, $ref, $input, 'Met at follow-up 1.');
        }
    }

    $answers = Rescoring::answers($this->assessment, 1);
    expect(ScoreGaps::for($answers))->toBe([]);

    $this->j->holdFollowUp($this->assessment, 1);
    $baseline = Rescoring::inForce($this->assessment, 2);
    $score = ($this->score)();

    expect($baseline->follow_up_number)->toBe(1)
        ->and((float) $score->points_achieved)->toBe(84.0)
        ->and((int) $score->points_available)->toBe(84)
        ->and((float) $score->pct)->toBe(100.0)
        ->and($score->baseline)->toBe(2)
        // The newest baseline is the movement's score everywhere.
        ->and((float) MovementStatus::query()->where('movement_id', $this->j->zambia->id)->value('pct'))->toBe(100.0)
        ->and(array_keys($baseline->changes))->not->toBeEmpty()
        ->and($baseline->changes[array_key_first($baseline->changes)])->toHaveKeys(['from', 'to', 'reason', 'by_name', 'via']);
});

it('scores "Not Applicable" 0 with the maximum unchanged, as the Excel form does, and flags a "Yes" without evidence', function () {
    $this->j->openFollowUp($this->assessment);
    $this->rescore->change($this->assessment, $this->j->assessor, 'q:Q203', 'Not Applicable', 'No internal controls are mandated.');
    $this->rescore->change($this->assessment, $this->j->assessor, 'q:Q201', 'Yes', 'The budget is now approved.');
    $answers = Rescoring::answers($this->assessment, 1);

    expect(collect(ScoreGaps::for($answers)['financial'])->firstWhere('code', 'Q203'))->toMatchArray(['points' => 0, 'max' => 1])
        ->and(Rescoring::evidenceGaps($this->assessment, $answers, 1))->toContain('Q201');

    // Set back: the change is gone.
    $this->rescore->undo($this->assessment, $this->j->assessor, 'Q203');
    expect(Rescoring::changes(Rescoring::window($this->assessment, 1)->answer_changes, Rescoring::inForce($this->assessment, 1)->answers, Rescoring::answers($this->assessment, 1)))
        ->not->toHaveKey('Q203');
});

it('re-scores from a re-filled Excel form exactly as the form is scored when uploaded', function () {
    $start = Rescoring::inForce($this->assessment, 1);
    $upload = FormUpload::query()->find($start->form_upload_id);
    $items = array_values(array_filter(array_merge(...array_values(ScoreGaps::for($start->answers))), fn ($i) => $i['options'] !== null && $i['options'] !== []));
    [$first, $second] = [$items[0], $items[1]];

    // The movement's form, re-filled: two answers changed.
    $book = IOFactory::load(zambiaFormPath());
    foreach ([$first, $second] as $item) {
        $cell = $upload->form_meta['cells']['answers'][$item['code']];
        $book->getSheetByName($cell['sheet'])->setCellValue($cell['cell'], $item['options'][0]);
    }
    $path = tempnam(sys_get_temp_dir(), 'oha').'.xlsx';
    IOFactory::createWriter($book, 'Xlsx')->save($path);

    $this->j->openFollowUp($this->assessment);
    $rescored = $this->rescore->upload($this->assessment, $this->j->secondAdmin, $path, 'ZAM26 OHA Form re-filled.xlsx', 'Re-filled at follow-up 1');

    $read = app(FormReader::class)->read($path);
    $excel = app(FormChecker::class)->check($read, $this->j->zambia)->points;
    expect($rescored->changed)->toEqualCanonicalizing([$first['code'], $second['code']])
        ->and(Rescoring::answers($this->assessment, 1))->toEqual($read->answers);

    $this->j->holdFollowUp($this->assessment, 1);
    $baseline = Rescoring::inForce($this->assessment, 2);
    expect($baseline->scores()->with('category')->get()->mapWithKeys(fn ($s) => [$s->category->code => (int) $s->points])->all())
        ->toEqual(array_filter($excel, fn ($p) => $p !== null))
        ->and((float) ($this->score)()->points_achieved)->toBe((float) $baseline->total->points_achieved)
        ->and($baseline->changes[$first['code']]['via'])->toBe('form');
    @unlink($path);
});

it('refuses a form for another movement, or with a sheet missing', function () {
    $this->j->openFollowUp($this->assessment);
    $book = IOFactory::load(zambiaFormPath());
    $book->removeSheetByIndex($book->getIndex($book->getSheetByName(collect($book->getSheetNames())->first(fn ($n) => str_starts_with($n, '9 ')))));
    $path = tempnam(sys_get_temp_dir(), 'oha').'.xlsx';
    IOFactory::createWriter($book, 'Xlsx')->save($path);

    expect(fn () => $this->rescore->upload($this->assessment, $this->j->assessor, $path, 'partial.xlsx'))
        ->toThrow(WorkflowRuleBroken::class, 'Upload the complete form');
    @unlink($path);
});

it('lets the assessors and Administrators re-score only while a follow-up is open', function () {
    expect(fn () => $this->rescore->change($this->assessment, $this->j->assessor, 'q:Q201', 'Yes', 'Approved.'))
        ->toThrow(WorkflowRuleBroken::class, 'Open the follow-up first');

    $this->j->openFollowUp($this->assessment);
    expect($this->j->assessor->can('rescore', $this->assessment))->toBeTrue()
        ->and($this->j->secondAdmin->can('rescore', $this->assessment))->toBeTrue()
        ->and($this->j->superAdmin->can('rescore', $this->assessment))->toBeTrue()
        ->and($this->ngs->can('rescore', $this->assessment))->toBeFalse()
        ->and($this->j->otherStaff->can('rescore', $this->assessment))->toBeFalse()
        ->and($this->j->chair->can('rescore', $this->assessment))->toBeFalse();
    expect(fn () => $this->rescore->change($this->assessment, $this->j->assessor, 'q:Q201', 'Yes', ''))
        ->toThrow(WorkflowRuleBroken::class, 'Say why');

    $page = route('baselines.show', $this->assessment);
    $this->actingAs($this->j->assessor)->get($page)->assertOk()->assertSee('Re-scoring for Baseline 2')->assertSee('Points still open')->assertSee('Re-score from this form');
    $this->actingAs($this->ngs)->get($page)->assertOk()->assertSee('Baseline 1')->assertDontSee('Re-score from this form');
    $this->actingAs($this->j->chair)->get($page)->assertForbidden();
});

it('sets a baseline aside when its follow-up is reopened, keeps it, and never lets one be edited', function () {
    $this->j->openFollowUp($this->assessment);
    $this->rescore->change($this->assessment, $this->j->assessor, 'q:Q238', 'Yes', 'Two years of costs are covered.');
    $this->j->holdFollowUp($this->assessment, 1);
    $first = Rescoring::inForce($this->assessment, 2);

    // Each refused inside its own savepoint, so the test's transaction carries on.
    expect(fn () => DB::transaction(fn () => DB::table('baselines')->where('id', $first->id)->update(['number' => 3])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('baseline_scores')->where('baseline_id', $first->id)->update(['points' => 0])))->toThrow(QueryException::class);

    app(FollowUpWindows::class)->reopen($this->assessment, $this->j->admin, 1, 'The meeting date was wrong.');
    expect($first->refresh()->isSuperseded())->toBeTrue()
        ->and($first->superseded_reason)->toBe('The meeting date was wrong.')
        ->and(($this->score)()->baseline)->toBe(1);

    // Its re-scored answers are still there; closing it again records a new Baseline 2.
    app(FollowUpWindows::class)->close($this->assessment, $this->j->admin, 1);
    $again = Rescoring::inForce($this->assessment, 2);
    expect($again->id)->not->toBe($first->id)
        ->and($again->answers['Q238'])->toBe('Yes')
        ->and(Baseline::query()->where('assessment_id', $this->assessment->id)->count())->toBe(3)
        ->and(($this->score)()->baseline)->toBe(2);
});
