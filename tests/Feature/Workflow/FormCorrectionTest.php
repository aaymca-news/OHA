<?php

use App\Actions\Oha\ApproveArtefact;
use App\Actions\Oha\DeleteFormUpload;
use App\Actions\Oha\OpenAssessment;
use App\Actions\Oha\SubmitForApproval;
use App\Actions\Oha\SupplyAnswer;
use App\Actions\Oha\UploadForm;
use App\Enums\ArtefactState;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\ArtefactStatus;
use App\Models\Assessment;
use App\Models\AuditEvent;
use App\Models\CategoryScore;
use App\Models\FormFinding;
use App\Models\FormUpload;
use App\Notifications\WorkflowNotice;
use App\Oha\Scorer;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\Journey;

/*
 * Correcting the OHA form: what it lacks is typed in the platform, in the form its
 * question asks for, and the same file is checked and scored again with it. An approved
 * form can be corrected (typed answers or a new upload) until the Board Chairperson signs
 * the ODP; it then goes back to the Administrators, who are told. A file uploaded by
 * mistake can be deleted, unless it is the approved one.
 */

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
    $this->gap = fn (Assessment $a, string $ref) => FormFinding::query()
        ->whereHas('formUpload', fn ($q) => $q->whereKey($this->j->form($a)->currentUpload()->value('id')))
        ->where('ref', $ref)->firstOrFail();
    $this->upload = fn (Assessment $a) => $this->j->form($a)->currentUpload()->firstOrFail();
    $this->supply = fn (FormFinding $gap, string|array $value) => app(SupplyAnswer::class)->handle($gap, $this->j->assessor, $value);
});

/** The Zambia form with some answers changed in the file itself, by question code (category sheets only). */
function zambiaFormWith(array $answers): string
{
    $book = IOFactory::createReader('Xlsx')->load(zambiaFormPath());
    foreach ($book->getWorksheetIterator() as $sheet) {
        for ($r = 1; $r <= $sheet->getHighestRow(); $r++) {
            $code = trim((string) $sheet->getCell('B'.$r)->getValue());
            if (array_key_exists($code, $answers)) {
                $sheet->getCell('D'.$r)->setValue($answers[$code]);
            }
        }
    }
    $path = tempnam(sys_get_temp_dir(), 'zam').'.xlsx';
    IOFactory::createWriter($book, 'Xlsx')->save($path);

    return $path;
}

it('takes a typed answer for what is missing, keeps who typed it, and checks the form again with it', function () {
    $assessment = $this->j->assessmentAt('form_uploaded');
    $file = ($this->upload)($assessment)->sha256;

    ($this->supply)(($this->gap)($assessment, 'q:Q246'), 'USD 12,500');

    $upload = ($this->upload)($assessment);
    expect($upload->answers['Q246'])->toBe(12500)
        ->and($upload->supplied['q:Q246']['value'])->toBe(12500)
        ->and($upload->supplied['q:Q246']['by_name'])->toBe('Tendai Moyo')
        // The gap is gone; the others stay. The file itself is untouched.
        ->and($upload->findings()->where('ref', 'q:Q246')->exists())->toBeFalse()
        ->and($upload->findings()->where('ref', 'q:Q911')->exists())->toBeTrue()
        ->and($upload->sha256)->toBe($file)
        ->and(Storage::disk('oha')->exists($upload->path))->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'form.answer_supplied')->value('payload')['ref'])->toBe('q:Q246');

    // The areas of improvement, and a text answer.
    ($this->supply)(($this->gap)($assessment, 'c:financial'), 'Diversify income and build a cash reserve of six months.');
    ($this->supply)(($this->gap)($assessment, 'q:Q911'), "Plot 12, Lusaka\nCamp site, Kafue");
    expect(($this->upload)($assessment)->form_meta['comments']['financial'])->toContain('Diversify income')
        ->and(($this->upload)($assessment)->findings()->where('severity', 'missing')->count())->toBe(0);
});

it('scores a typed answer, and asks for what it makes necessary', function () {
    $assessment = app(OpenAssessment::class)->handle($this->j->assessor, $this->j->zambia, 'Feb 2026', now());
    app(UploadForm::class)->handle($this->j->form($assessment), zambiaFormWith(['Q201' => null, 'Q239' => null]), 'ZAM26.xlsx', $this->j->assessor);
    $points = fn () => array_sum(array_filter(app(Scorer::class)->score(($this->upload)($assessment)->answers)));
    $before = $points();

    // Q201 is a Yes/No question worth a point: "Y" is read as Yes.
    ($this->supply)(($this->gap)($assessment, 'q:Q201'), 'Y');
    expect(($this->upload)($assessment)->answers['Q201'])->toBe('Yes')
        ->and($points())->toBe($before + 1);

    // A cash reserve (Q239 "Yes") makes its value and how long it lasts necessary.
    ($this->supply)(($this->gap)($assessment, 'q:Q239'), 'Yes');
    expect(($this->upload)($assessment)->findings()->pluck('ref')->all())->toContain('q:Q240', 'q:Q241');

    // Months asked: years are refused, not converted.
    expect(fn () => ($this->supply)(($this->gap)($assessment, 'q:Q241'), '2 years'))
        ->toThrow(WorkflowRuleBroken::class, 'That is years; the form asks for the number of months.');
    ($this->supply)(($this->gap)($assessment, 'q:Q241'), 'eight');
    expect(($this->upload)($assessment)->answers['Q241'])->toBe(8)
        ->and($points())->toBe($before + 1 + 1 + 1); // Q239 Yes, and a reserve of more than 6 months.
});

it('refuses answers the question cannot take', function () {
    $assessment = app(OpenAssessment::class)->handle($this->j->assessor, $this->j->zambia, 'Feb 2026', now());
    app(UploadForm::class)->handle($this->j->form($assessment), zambiaFormWith(['Q201' => null, 'Q214' => null, 'Q220' => null, 'Q221' => null, 'Q215' => null, 'Q216' => null, 'Q217' => null, 'Q218' => null, 'Q219' => null, 'Q222' => null]), 'ZAM26.xlsx', $this->j->assessor);

    expect(fn () => ($this->supply)(($this->gap)($assessment, 'q:Q201'), 'Perhaps'))->toThrow(WorkflowRuleBroken::class, 'Choose one of: Yes, No.')
        ->and(fn () => ($this->supply)(($this->gap)($assessment, 'q:Q246'), '200 kg'))->toThrow(WorkflowRuleBroken::class, 'That is a weight')
        ->and(fn () => ($this->supply)(($this->gap)($assessment, 'q:Q246'), 'quite a lot'))->toThrow(WorkflowRuleBroken::class, 'Type the amount as one figure')
        ->and(fn () => ($this->supply)(($this->gap)($assessment, 'c:financial'), 'Money'))->toThrow(WorkflowRuleBroken::class, 'areas of improvement')
        // A group of percentages must add up to 100.
        ->and(fn () => ($this->supply)(($this->gap)($assessment, 'g:income'), ['Q214' => '30', 'Q220' => '30']))->toThrow(WorkflowRuleBroken::class, 'add up to 60%')
        ->and(fn () => ($this->supply)(($this->gap)($assessment, 'g:income'), ['Q214' => '130']))->toThrow(WorkflowRuleBroken::class, 'between 0 and 100');

    ($this->supply)(($this->gap)($assessment, 'g:income'), ['Q214' => '40%', 'Q220' => '35', 'Q221' => 'twenty-five']);
    expect(collect(($this->upload)($assessment)->answers)->only('Q214', 'Q220', 'Q221')->all())->toBe(['Q214' => 40, 'Q220' => 35, 'Q221' => 25]);
});

it('takes back a typed answer, and the gap returns', function () {
    $assessment = $this->j->assessmentAt('form_uploaded');
    ($this->supply)(($this->gap)($assessment, 'q:Q246'), '12500');

    app(SupplyAnswer::class)->withdraw(($this->upload)($assessment), 'q:Q246', $this->j->assessor);

    expect(($this->upload)($assessment)->supplied)->toBeNull()
        ->and(($this->upload)($assessment)->answers['Q246'] ?? null)->toBeNull()
        ->and(($this->gap)($assessment, 'q:Q246'))->toBeInstanceOf(FormFinding::class);
});

it('lets only the assessors and the Administrators type answers, and never while the form is with the Administrators', function () {
    $assessment = $this->j->assessmentAt('form_uploaded');
    $gap = ($this->gap)($assessment, 'q:Q246');

    expect(fn () => app(SupplyAnswer::class)->handle($gap, $this->j->otherStaff, '100'))->toThrow(WorkflowRuleBroken::class, 'Only the assessors')
        ->and(fn () => app(SupplyAnswer::class)->handle($gap, $this->j->chair, '100'))->toThrow(WorkflowRuleBroken::class, 'Only the assessors');
    app(SupplyAnswer::class)->handle($gap, $this->j->admin, '100');

    app(SubmitForApproval::class)->handle($this->j->form($assessment), $this->j->assessor, acknowledgeGaps: true);
    expect(fn () => ($this->supply)(($this->gap)($assessment, 'q:Q911'), 'A plot'))->toThrow(WorkflowRuleBroken::class, 'with the Administrators');
});

it('sends an approved form back for approval when it is corrected, telling the Administrators, and keeps the approved score until then', function () {
    $assessment = $this->j->assessmentAt('form_approved');
    $recorded = CategoryScore::query()->where('assessment_id', $assessment->id)->sum('points');
    $approved = ($this->upload)($assessment);
    expect($approved->isApproved())->toBeTrue()->and($approved->approved_by)->toBe($this->j->admin->id);

    // Corrected by typing: back to the Administrators, who are told.
    ($this->supply)(($this->gap)($assessment, 'q:Q246'), '12500');
    expect($this->j->form($assessment)->state)->toBe(ArtefactState::Ready);
    Notification::assertSentTo([$this->j->admin, $this->j->secondAdmin, $this->j->superAdmin], WorkflowNotice::class,
        fn (WorkflowNotice $n) => $n->subject === 'Changed after approval: OHA form, Zambia YMCA'
            && str_contains($n->body, 'Tendai Moyo typed an answer in the approved OHA form'));

    // Everyone still sees the approved form and its score.
    expect(ArtefactStatus::query()->findOrFail($this->j->form($assessment)->id)->published)->toBeTrue()
        ->and(CategoryScore::query()->where('assessment_id', $assessment->id)->sum('points'))->toEqual($recorded);
    $this->actingAs($this->j->otherStaff)->get(route('assessments.show', ['assessment' => $assessment, 'tab' => 'form']))
        ->assertOk()->assertDontSee('Recomputed from the answers');

    // Approved again: the score is recorded afresh, from the corrected answers.
    app(SubmitForApproval::class)->handle($this->j->form($assessment), $this->j->assessor, acknowledgeGaps: true);
    app(ApproveArtefact::class)->handle($this->j->form($assessment), $this->j->secondAdmin);
    expect(CategoryScore::query()->where('assessment_id', $assessment->id)->value('form_upload_id'))->toBe(($this->upload)($assessment)->id)
        ->and(CategoryScore::query()->where('assessment_id', $assessment->id)->count())->toBe(8);
});

it('takes a corrected file after approval, and a new report version, and tells the Administrators', function () {
    $assessment = $this->j->assessmentAt('report_approved');

    app(UploadForm::class)->handle($this->j->form($assessment), zambiaFormPath(), 'ZAM26 corrected.xlsx', $this->j->assessor);
    expect($this->j->form($assessment)->state)->toBe(ArtefactState::Ready);
    Notification::assertSentTo($this->j->admin, WorkflowNotice::class, fn (WorkflowNotice $n) => str_contains($n->body, 'uploaded a new file for the approved OHA form'));

    $this->j->uploadReport($assessment, 'Zambia OHA report, corrected');
    expect($this->j->report($assessment)->state)->toBe(ArtefactState::Drafted);
    Notification::assertSentTo($this->j->admin, WorkflowNotice::class, fn (WorkflowNotice $n) => $n->subject === 'Changed after approval: Health assessment report, Zambia YMCA'
        && str_contains($n->body, 'saved version 2 of the approved health assessment report'));
});

it('freezes the form, the report and the ODP once the Board Chairperson signs the ODP', function () {
    $assessment = $this->j->assessmentAt('odp_signed');

    expect($assessment->isFrozen())->toBeTrue()
        ->and(fn () => app(UploadForm::class)->handle($this->j->form($assessment), zambiaFormPath(), 'ZAM26.xlsx', $this->j->assessor))->toThrow(WorkflowRuleBroken::class, 'frozen')
        ->and(fn () => ($this->supply)(($this->gap)($assessment, 'q:Q246'), '100'))->toThrow(WorkflowRuleBroken::class, 'frozen')
        ->and(fn () => $this->j->uploadReport($assessment, 'Another report'))->toThrow(WorkflowRuleBroken::class, 'frozen')
        ->and(fn () => $this->j->uploadOdp($assessment, 'Another ODP'))->toThrow(WorkflowRuleBroken::class, 'frozen');

    $this->actingAs($this->j->assessor)->get(route('assessments.show', ['assessment' => $assessment, 'tab' => 'form']))
        ->assertSee('Frozen.')->assertDontSee('Upload a corrected OHA form')->assertDontSee('Type the answer');
});

it('deletes an upload made by mistake, never the approved one, and the form stands as before', function () {
    $assessment = $this->j->assessmentAt('form_approved');
    $approved = ($this->upload)($assessment);

    expect(fn () => app(DeleteFormUpload::class)->handle($approved, $this->j->assessor))->toThrow(WorkflowRuleBroken::class, 'it is the record of the score');

    $mistake = app(UploadForm::class)->handle($this->j->form($assessment), zambiaFormWith(['Q201' => 'No']), 'wrong file.xlsx', $this->j->assessor);
    expect($this->j->form($assessment)->state)->toBe(ArtefactState::Ready)
        ->and(fn () => app(DeleteFormUpload::class)->handle($mistake, $this->j->otherStaff))->toThrow(WorkflowRuleBroken::class, 'Only the assessors');

    app(DeleteFormUpload::class)->handle($mistake, $this->j->assessor);

    expect(FormUpload::query()->find($mistake->id))->toBeNull()
        ->and(Storage::disk('oha')->exists($mistake->path))->toBeFalse()
        ->and($this->j->form($assessment)->state)->toBe(ArtefactState::Approved)
        ->and(($this->upload)($assessment)->id)->toBe($approved->id)
        ->and(AuditEvent::query()->where('action', 'form.upload_deleted')->value('payload')['file'])->toBe('wrong file.xlsx');
});

it('deletes the only upload back to not started, but keeps the only form a report rests on', function () {
    $assessment = $this->j->assessmentAt('form_uploaded');
    app(DeleteFormUpload::class)->handle(($this->upload)($assessment), $this->j->assessor);
    expect($this->j->form($assessment)->state)->toBe(ArtefactState::NotStarted);

    // The report unlocks once a form is read; from then on the only form stays.
    app(UploadForm::class)->handle($this->j->form($assessment), zambiaFormPath(), 'ZAM26.xlsx', $this->j->assessor);
    $this->j->uploadReport($assessment, 'Zambia OHA report');
    expect(fn () => app(DeleteFormUpload::class)->handle(($this->upload)($assessment), $this->j->assessor))
        ->toThrow(WorkflowRuleBroken::class, 'The report rests on this form');
});

it('shows the uploaded form as a workbook, and what was read from words, on the form tab', function () {
    $assessment = app(OpenAssessment::class)->handle($this->j->assessor, $this->j->zambia, 'Feb 2026', now());
    app(UploadForm::class)->handle($this->j->form($assessment), zambiaFormWith(['Q1013' => '385 volunteers', 'Q201' => 'Y']), 'ZAM26.xlsx', $this->j->assessor);

    $this->actingAs($this->j->assessor)->get(route('assessments.show', ['assessment' => $assessment, 'tab' => 'form']))
        ->assertOk()
        ->assertSee('Read from words')->assertSee('“385 volunteers”', false)
        ->assertSee('Preview · ZAM26.xlsx')->assertSee('1 General Information')->assertSee('2 Financial Stability')
        ->assertSee('Fit to width')
        ->assertSee('Type the answer')->assertSee('Delete this upload');
});
