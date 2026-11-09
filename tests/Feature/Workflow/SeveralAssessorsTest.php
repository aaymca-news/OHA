<?php

use App\Actions\Oha\SubmitForApproval;
use App\Actions\Oha\UploadForm;
use App\Models\Assessment;
use App\Models\AuditEvent;
use App\Models\FormFinding;
use App\Models\User;
use App\Notifications\WorkflowNotice;
use App\Support\ReplaceGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Journey;

/*
 * Several assessors on one movement: each sees and does what any assessor may, and the
 * platform keeps them from undoing each other's work unseen. Replacing the current
 * version is confirmed (with a reason, when it is a colleague's), a page opened before
 * someone else's change cannot overwrite it, a version saved by mistake can be withdrawn,
 * the others are told in the app, and the audit trail shows who did what.
 */

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
    $this->kofi = User::factory()->create(['name' => 'Kofi Mensah']);
    $this->kofi->assignedMovements()->attach($this->j->zambia);
    $this->version = fn (string $content) => new UploadedFile(Journey::reportFile($content), 'Zambia OHA Report 2026.pdf', null, null, true);
    $this->save = fn (User $by, Assessment $assessment, string $content, array $extra = []) => $this->actingAs($by)
        ->post(route('artefacts.versions', $this->j->report($assessment)), ['report' => ($this->version)($content)] + $extra);
});

it('gives every assessor on the movement the same access', function () {
    $assessment = $this->j->assessmentAt('report_uploaded');
    $report = $this->j->report($assessment);

    foreach ([$this->j->assessor, $this->kofi] as $assessor) {
        expect($assessor->canAssess($this->j->zambia))->toBeTrue()
            ->and($assessor->can('view', $report))->toBeTrue()
            ->and($assessor->can('upload', $report))->toBeTrue()
            ->and($assessor->can('submit', $report))->toBeTrue()
            ->and($assessor->can('viewGaps', $assessment))->toBeTrue()
            ->and($assessor->can('viewAudit', $assessment))->toBeTrue()
            ->and($assessor->can('download', $report->versions()->first()))->toBeTrue();
        $this->actingAs($assessor)->get(route('assessments.show', ['assessment' => $assessment, 'tab' => 'report']))->assertOk()
            ->assertSee('Upload a new version');
    }
});

it('asks for a tick, and a reason, before replacing a colleague’s version, and keeps both in the audit trail', function () {
    $assessment = $this->j->assessmentAt('report_uploaded');
    $theirs = $this->j->report($assessment)->versions()->first();

    // The page shows who saved the current version, and asks why.
    $this->actingAs($this->kofi)->get(route('assessments.show', ['assessment' => $assessment, 'tab' => 'report']))
        ->assertSee('Version 1 was saved by Tendai Moyo', false)->assertSee('I want to replace it')->assertSee('Why are you replacing it?');

    ($this->save)($this->kofi, $assessment, 'mine', ['seen' => $theirs->id])
        ->assertSessionHasErrors(['action' => 'Tick the box to confirm you are replacing version 1.']);
    ($this->save)($this->kofi, $assessment, 'mine', ['seen' => $theirs->id, 'replace' => 1])
        ->assertSessionHasErrors('action');
    expect($this->j->report($assessment)->versions()->count())->toBe(1);

    ($this->save)($this->kofi, $assessment, 'mine', ['seen' => $theirs->id, 'replace' => 1, 'replace_reason' => 'Corrected the staff figures.'])
        ->assertSessionHasNoErrors();

    $event = AuditEvent::query()->where('action', 'report.version_uploaded')->where('actor_id', $this->kofi->id)->firstOrFail();
    expect($event->payload['replaced'])->toStartWith('version 1 saved by Tendai Moyo')
        ->and($event->payload['reason'])->toBe('Corrected the staff figures.');
    $this->actingAs($this->j->assessor)->get(route('assessments.show', ['assessment' => $assessment, 'tab' => 'audit']))
        ->assertSee('Replaced version 1 saved by Tendai Moyo', false)->assertSee('Corrected the staff figures.');
});

it('needs only the tick to replace one’s own version', function () {
    $assessment = $this->j->assessmentAt('report_uploaded');
    $mine = $this->j->report($assessment)->versions()->first();

    ($this->save)($this->j->assessor, $assessment, 'again', ['seen' => $mine->id, 'replace' => 1])->assertSessionHasNoErrors();
    expect($this->j->report($assessment)->versions()->count())->toBe(2);
});

it('stops an upload or a submission from a page opened before a colleague’s change', function () {
    $assessment = $this->j->assessmentAt('report_uploaded');
    $seen = $this->j->report($assessment)->versions()->first()->id;

    // Kofi saves a new version while Tendai still has the old page open.
    $this->j->uploadReport($assessment, 'Kofi’s version', $this->kofi);

    ($this->save)($this->j->assessor, $assessment, 'tendai', ['seen' => $seen, 'replace' => 1])
        ->assertSessionHasErrors(['action' => 'Version 2 was saved by Kofi Mensah, '.now()->format('j M \a\t H:i').', after you opened this page. Reload the page to see it before replacing it.']);
    $this->actingAs($this->j->assessor)->post(route('artefacts.submit', $this->j->report($assessment)), ['seen' => $seen])
        ->assertSessionHasErrors('action');
    expect($this->j->report($assessment)->refresh()->state->value)->toBe('drafted');

    // With the page reloaded, the submission goes through.
    $this->actingAs($this->j->assessor)->post(route('artefacts.submit', $this->j->report($assessment)), ['seen' => ReplaceGuard::current($this->j->report($assessment))->id])
        ->assertSessionHasNoErrors();
});

it('tells the other assessors, in the app only, when a colleague saves a version or submits', function () {
    $assessment = $this->j->assessmentAt('report_uploaded');
    $this->j->uploadReport($assessment, 'Kofi’s version', $this->kofi);

    Notification::assertSentTo($this->j->assessor, WorkflowNotice::class, fn (WorkflowNotice $n, array $channels) => $n->subject === 'New report version: Zambia YMCA' && $channels === ['database']);
    // Never told of their own change (Kofi was told of Tendai's first version, as he should be).
    Notification::assertNotSentTo($this->kofi, WorkflowNotice::class, fn (WorkflowNotice $n) => str_starts_with($n->body, 'Kofi Mensah saved'));

    app(SubmitForApproval::class)->handle($this->j->report($assessment), $this->kofi);
    Notification::assertSentTo($this->j->assessor, WorkflowNotice::class, fn (WorkflowNotice $n, array $channels) => str_starts_with($n->subject, 'Submitted: Health assessment report') && $channels === ['database']);
});

it('keeps the version a new one replaced, so a mistaken one can be withdrawn and the colleague’s is current again', function () {
    $assessment = $this->j->assessmentAt('report_uploaded');
    $tendais = $this->j->report($assessment)->versions()->first();
    $kofis = $this->j->uploadReport($assessment, 'Kofi’s version', $this->kofi);
    $this->j->uploadReport($assessment, 'Kofi’s third', $this->kofi);
    $report = $this->j->report($assessment);

    // The newest and the one before it are kept; older drafts go.
    expect($report->versions()->pluck('id')->contains($kofis->id))->toBeTrue()
        ->and($report->versions()->pluck('id')->contains($tendais->id))->toBeFalse();

    $newest = ReplaceGuard::current($report);
    // Tendai may not withdraw Kofi's version; Kofi may.
    expect($this->j->assessor->can('withdraw', $newest))->toBeFalse()
        ->and($this->kofi->can('withdraw', $newest))->toBeTrue()
        ->and($this->j->admin->can('withdraw', $newest))->toBeTrue()
        ->and($this->kofi->can('withdraw', $kofis))->toBeFalse();

    $this->actingAs($this->kofi)->delete(route('versions.withdraw', $newest))->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Version 3 withdrawn. Version 2 is current again.');
    expect(ReplaceGuard::current($report)->id)->toBe($kofis->id)
        ->and(AuditEvent::query()->where('action', 'report.version_withdrawn')->where('actor_id', $this->kofi->id)->exists())->toBeTrue();
    Notification::assertSentTo($this->j->assessor, WorkflowNotice::class, fn (WorkflowNotice $n) => str_starts_with($n->subject, 'Withdrawn: report version 3'));
});

it('never withdraws an approved version, or one with the Administrators', function () {
    $assessment = $this->j->assessmentAt('report_submitted');
    $submitted = ReplaceGuard::current($this->j->report($assessment));
    expect($this->j->assessor->can('withdraw', $submitted))->toBeFalse();

    $assessment = $this->j->assessmentAt('report_approved');
    expect($this->j->admin->can('withdraw', ReplaceGuard::current($this->j->report($assessment))))->toBeFalse();
});

it('lets only whoever uploaded an OHA form, or an Administrator, delete it', function () {
    $assessment = $this->j->assessmentAt('form_uploaded');
    $tendais = $this->j->form($assessment)->uploads()->first();
    $kofis = app(UploadForm::class)->handle($this->j->form($assessment), zambiaFormPath(), 'ZAM26 corrected.xlsx', $this->kofi);

    expect($this->j->assessor->can('delete', $kofis))->toBeFalse()
        ->and($this->kofi->can('delete', $kofis))->toBeTrue()
        ->and($this->j->admin->can('delete', $kofis))->toBeTrue();

    // Deleting Kofi's leaves Tendai's, kept for this, current again.
    $this->actingAs($this->kofi)->delete(route('form-uploads.destroy', $kofis))->assertSessionHasNoErrors();
    expect($this->j->form($assessment)->currentUpload()->value('id'))->toBe($tendais->id);
});

it('says who resolved a gap when a colleague tries again', function () {
    $assessment = $this->j->assessmentAt('form_uploaded');
    $gap = FormFinding::query()->whereHas('formUpload', fn ($q) => $q->whereHas('artefact', fn ($a) => $a->where('assessment_id', $assessment->id)))
        ->where('severity', 'missing')->whereNull('resolved_at')->firstOrFail();

    $this->actingAs($this->kofi)->post(route('findings.resolve', $gap), ['note' => 'Confirmed by phone with the movement.'])->assertSessionHasNoErrors();
    $this->actingAs($this->j->assessor)->post(route('findings.resolve', $gap), ['note' => 'Also confirmed.'])
        ->assertSessionHasErrors(['action' => 'That item is already resolved, by Kofi Mensah on '.now()->format('j M \a\t H:i').'. Reload the page to see it.']);
});

it('shows who last changed each document, and narrows the audit trail to one person', function () {
    $assessment = $this->j->assessmentAt('report_uploaded');
    $this->j->uploadReport($assessment, 'Kofi’s version', $this->kofi);

    $this->actingAs($this->j->assessor)->get(route('assessments.show', ['assessment' => $assessment, 'tab' => 'report']))
        ->assertSee('Last change:')->assertSee('Kofi Mensah');

    $this->actingAs($this->j->assessor)->get(route('assessments.show', ['assessment' => $assessment, 'tab' => 'audit', 'who' => $this->kofi->id]))
        ->assertOk()->assertSee('report: version uploaded')->assertDontSee('form: uploaded');
    $this->actingAs($this->j->assessor)->get(route('assessments.show', ['assessment' => $assessment, 'tab' => 'audit']))
        ->assertSee('form: uploaded')->assertSee('<option value="'.$this->kofi->id.'"', false);
});
