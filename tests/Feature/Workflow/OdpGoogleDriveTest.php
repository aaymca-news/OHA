<?php

use App\Actions\Oha\ApproveArtefact;
use App\Actions\Oha\LinkOdpToDrive;
use App\Actions\Oha\SubmitForApproval;
use App\Actions\Oha\TakeOdpFromDrive;
use App\Enums\ArtefactState;
use App\Enums\DocumentFormat;
use App\Enums\DocumentPurpose;
use App\Enums\DocumentSource;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\AssessmentMilestone;
use App\Models\AuditEvent;
use App\Models\DriveSyncRun;
use App\Notifications\WorkflowNotice;
use App\Support\Google\DriveFile;
use App\Support\Google\DriveLink;
use App\Support\Google\GoogleDrive;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FakeDrive;
use Tests\Support\Journey;

/*
 * The ODP is written in Google Drive. Staff upload it in versions with the link to
 * that document; once the platform can read Google Drive, each change made there is
 * saved as a new version by itself, and goes through approval like any other.
 */

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
    $this->drive = new FakeDrive;
    app()->instance(GoogleDrive::class, $this->drive);
    $this->take = fn ($assessment, $by = null) => app(TakeOdpFromDrive::class)->handle($this->j->odp($assessment), $by);
});

it('reads the file ID from the links people copy (Sheets, Docs, Drive files), and refuses anything else', function () {
    $id = '1ZaMbIaOdP2026xYzAbCdEfGhIjKlMnOpQr';

    foreach ([
        "https://docs.google.com/spreadsheets/d/{$id}/edit?gid=0#gid=0",
        "https://docs.google.com/spreadsheets/u/0/d/{$id}/edit",
        "https://docs.google.com/document/d/{$id}/edit?usp=sharing",
        "https://docs.google.com/document/u/0/d/{$id}/edit",
        "https://drive.google.com/file/d/{$id}/view?usp=drive_link",
        "https://drive.google.com/open?id={$id}",
        "https://drive.google.com/uc?id={$id}&export=download",
    ] as $link) {
        expect(DriveLink::fileId($link))->toBe($id);
    }

    foreach ([
        "https://drive.google.com/drive/folders/{$id}" => 'link to a folder',
        "https://docs.google.com/presentation/d/{$id}/edit" => 'Google Slides',
        "http://docs.google.com/document/d/{$id}/edit" => 'starts with https://',
        "https://docs.google.com.evil.example/document/d/{$id}" => 'starts with https://',
        'https://docs.google.com/document/d/short/edit' => 'does not point to a file',
    ] as $link => $why) {
        expect(fn () => DriveLink::fileId($link))->toThrow(WorkflowRuleBroken::class, $why);
    }
});

it('saves a change made in Google Drive as a new version, recorded under the person who made it', function () {
    $assessment = $this->j->assessmentAt('odp_uploaded');
    $this->drive->edit('second draft, with the March board elections', $this->j->assessor->email);

    $result = ($this->take)($assessment);
    $odp = $this->j->odp($assessment);
    $version = $odp->latestVersion()->firstOrFail();

    expect($result['outcome'])->toBe(TakeOdpFromDrive::SAVED)
        ->and($version->versionNumber())->toBe(2)
        ->and($version->source)->toBe(DocumentSource::GoogleDrive)
        ->and($version->original_name)->toBe('Zambia ODP 2026.docx')
        ->and($version->created_by)->toBe($this->j->assessor->id)
        ->and($version->edited_by_email)->toBe($this->j->assessor->email)
        ->and($version->drive_version)->toBe(2)
        ->and($odp->drive_version)->toBe(2)
        ->and($odp->drive_problem)->toBeNull()
        ->and(AuditEvent::query()->where('action', 'odp.version_from_drive')->value('payload'))->toMatchArray(['version' => 2, 'drive_version' => 2]);

    // Nothing changed since: nothing is read again.
    expect(($this->take)($assessment)['outcome'])->toBe(TakeOdpFromDrive::UNCHANGED)
        ->and($this->drive->reads)->toBe(1)
        ->and($odp->versions()->count())->toBe(2);
});

it('takes a change to an ODP written as a Google Sheet as an Excel version', function () {
    $assessment = $this->j->assessmentAt('odp_uploaded');
    $this->drive->mimeType = DriveFile::GOOGLE_SHEET;
    $this->drive->edit('sheet with a new task', $this->j->assessor->email);

    $version = ($this->take)($assessment)['version'];

    expect($version->format)->toBe(DocumentFormat::Xlsx)
        ->and($version->original_name)->toBe('Zambia ODP 2026.xlsx')
        ->and($version->source)->toBe(DocumentSource::GoogleDrive);
});

it('leaves a document being edited until it has been quiet, unless someone asks for it now', function () {
    $assessment = $this->j->assessmentAt('odp_uploaded');
    $this->drive->edit('typing…', 'someone@africaymca.org', minutesAgo: 2);

    expect(($this->take)($assessment)['outcome'])->toBe(TakeOdpFromDrive::WAITING)
        ->and($this->j->odp($assessment)->versions()->count())->toBe(1);

    $result = ($this->take)($assessment, $this->j->assessor);

    expect($result['outcome'])->toBe(TakeOdpFromDrive::SAVED)
        // An editor with no account here: the version is recorded under whoever linked the document.
        ->and($result['version']->created_by)->toBe($this->j->assessor->id)
        ->and($result['version']->edited_by_email)->toBe('someone@africaymca.org');
});

it('sends an approved ODP changed in Google Drive back for approval, showing the approved version meanwhile', function () {
    $assessment = $this->j->assessmentAt('odp_approved');
    $approved = $this->j->odp($assessment)->approvedVersion()->firstOrFail();

    $this->drive->edit('added the youth programme targets', $this->j->admin->email);
    ($this->take)($assessment);
    $odp = $this->j->odp($assessment);

    expect($odp->state)->toBe(ArtefactState::Drafted)
        ->and($odp->isPublished())->toBeTrue()
        ->and($odp->approvedVersion()->firstOrFail()->id)->toBe($approved->id)
        ->and(Gate::forUser($this->j->chair)->allows('download', $approved))->toBeTrue()
        ->and(Gate::forUser($this->j->chair)->allows('download', $odp->latestVersion()->firstOrFail()))->toBeFalse()
        ->and(Gate::forUser($this->j->chair)->inspect('sign', $odp)->message())->toContain('newer version of the ODP is waiting for AAYMCA’s approval')
        // The ODP gate stays cleared by the first approval, as the report's does.
        ->and(AssessmentMilestone::query()->where('assessment_id', $assessment->id)->where('gate_code', 'odp')->value('done_at'))->not->toBeNull();

    Notification::assertSentTo($this->j->assessor, WorkflowNotice::class,
        fn ($n) => $n->subject === 'Approved ODP changed in Google Drive: Zambia YMCA' && str_contains($n->body, 'needs approval again'));

    // Re-approved: the Chairperson signs the new version.
    app(SubmitForApproval::class)->handle($odp, $this->j->assessor);
    app(ApproveArtefact::class)->handle($this->j->odp($assessment), $this->j->secondAdmin);
    $signature = $this->j->sign($this->j->odp($assessment));

    expect($signature->document->versionNumber())->toBe(2);
});

it('takes nothing while the ODP is with the Administrators, and once it is signed only notes changes, never versions', function () {
    $assessment = $this->j->assessmentAt('odp_submitted');
    $this->drive->edit('edited while waiting', $this->j->assessor->email);

    expect(($this->take)($assessment)['outcome'])->toBe(TakeOdpFromDrive::SKIPPED)
        ->and(fn () => ($this->take)($assessment, $this->j->assessor))->toThrow(WorkflowRuleBroken::class, 'taken once the Administrators decide');

    app(ApproveArtefact::class)->handle($this->j->odp($assessment), $this->j->admin);
    $this->j->sign($this->j->odp($assessment));

    // Signed: the change is noted for Stage 2, and the signed version stays the only one approved.
    $result = ($this->take)($assessment);
    expect($result['outcome'])->toBe(TakeOdpFromDrive::SAVED)
        ->and($result['version']->purpose)->toBe(DocumentPurpose::AfterSigning)
        ->and($this->j->odp($assessment)->versions()->count())->toBe(1)
        ->and($this->j->odp($assessment)->changesAfterSigning()->count())->toBe(1)
        ->and(($this->take)($assessment)['outcome'])->toBe(TakeOdpFromDrive::UNCHANGED)
        ->and(fn () => $this->j->uploadOdp($assessment, 'after signing'))->toThrow(WorkflowRuleBroken::class, 'frozen');

    // The link can still follow the document if the staff move to another copy.
    expect(app(LinkOdpToDrive::class)->handle($this->j->odp($assessment), Journey::DRIVE_URL, $this->j->assessor)->drive_url)->toBe(Journey::DRIVE_URL);

    $this->actingAs($this->j->assessor)->get(route('assessments.show', ['assessment' => $assessment, 'tab' => 'odp']))
        ->assertSee('Changes made after signing')->assertSee('Changed in Google Drive by '.$this->j->assessor->email);
});

it('tells the assessors when the Google Drive link stops working, and when it works again', function () {
    $assessment = $this->j->assessmentAt('odp_uploaded');
    $this->drive->failure = 'The ODP document is in the Google Drive bin. Restore it, or link the document that replaced it.';
    $this->drive->edit('deleted', null);

    ($this->take)($assessment);
    ($this->take)($assessment); // told once, not at every check
    $stopped = Notification::sent($this->j->assessor, WorkflowNotice::class, fn (WorkflowNotice $n) => str_contains($n->subject, 'Google Drive link has stopped working'));
    expect($stopped)->toHaveCount(1)
        ->and($stopped->first()->body)->toContain('in the Google Drive bin');

    $this->actingAs($this->j->assessor)->get(route('assessments.show', ['assessment' => $assessment, 'tab' => 'odp']))
        ->assertSee('The ODP’s Google Drive link is not working');

    $this->drive->failure = null;
    $this->drive->edit('restored', null);
    ($this->take)($assessment, $this->j->assessor);
    Notification::assertSentTo($this->j->assessor, WorkflowNotice::class, fn (WorkflowNotice $n) => str_contains($n->subject, 'link works again'));
});

it('records why a document could not be read, and shows it on the ODP tab', function () {
    $assessment = $this->j->assessmentAt('odp_uploaded');
    $this->drive->failure = 'The platform cannot open this document in Google Drive. Keep it in the OHA shared drive, or share it with oha-platform@aaymca-oha.iam.gserviceaccount.com (Viewer is enough).';
    $this->drive->edit('unreadable', null);

    expect(($this->take)($assessment)['outcome'])->toBe(TakeOdpFromDrive::FAILED)
        ->and($this->j->odp($assessment)->drive_problem)->toStartWith('The platform cannot open this document');

    $this->actingAs($this->j->assessor)->get(route('assessments.show', ['assessment' => $assessment, 'tab' => 'odp']))
        ->assertSee('The platform cannot open this document')->assertSee('Check Google Drive now');

    $this->drive->failure = null;
    ($this->take)($assessment);

    expect($this->j->odp($assessment)->drive_problem)->toBeNull();
});

it('says changes are taken by upload only while Google Drive is not connected', function () {
    $this->drive->connected = false;
    $assessment = $this->j->assessmentAt('odp_uploaded');
    $this->drive->edit('changed', $this->j->assessor->email);

    expect(($this->take)($assessment)['outcome'])->toBe(TakeOdpFromDrive::SKIPPED);
    $this->actingAs($this->j->assessor)->get(route('assessments.show', ['assessment' => $assessment, 'tab' => 'odp']))
        ->assertSee('By upload only, for now.')->assertDontSee('Check Google Drive now');
});

it('lets the movement’s assessors and the Administrators work on the ODP, and nobody else', function () {
    $assessment = $this->j->assessmentAt('report_uploaded');

    // An Administrator not assigned to Zambia.
    $this->j->uploadOdp($assessment, 'by an Administrator', by: $this->j->admin);

    expect(fn () => $this->j->uploadOdp($assessment, 'by other staff', by: $this->j->otherStaff))->toThrow(WorkflowRuleBroken::class, 'not assigned')
        ->and(fn () => $this->j->uploadOdp($assessment, 'by the chair', by: $this->j->chair))->toThrow(WorkflowRuleBroken::class, 'not assigned')
        ->and(Gate::forUser($this->j->otherStaff)->allows('view', $this->j->odp($assessment)))->toBeFalse();
});

it('checks every linked, unsigned ODP on a schedule and records each run', function () {
    $saved = $this->j->assessmentAt('odp_uploaded');
    $this->drive->edit('changed in Drive', $this->j->assessor->email);

    $this->artisan('oha:sync-drive')->assertSuccessful();

    $run = DriveSyncRun::query()->latest('id')->firstOrFail();
    expect($run->checked)->toBe(1)->and($run->saved)->toBe(1)->and($run->failed)->toBe(0)->and($run->finished_at)->not->toBeNull()
        ->and($this->j->odp($saved)->versions()->count())->toBe(2);

    $this->drive->connected = false;
    $this->artisan('oha:sync-drive')->assertSuccessful();

    expect(DriveSyncRun::query()->latest('id')->value('error'))->toContain('Not connected');
});

it('shows the latest ODP changes on the dashboard', function () {
    $assessment = $this->j->assessmentAt('odp_uploaded');
    $this->drive->edit('changed in Drive', $this->j->assessor->email);
    ($this->take)($assessment);

    $this->actingAs($this->j->admin)->get(route('dashboard'))->assertOk()
        ->assertSee('ODP changes')->assertSee('Zambia YMCA · version 2')->assertSee('Changed in Google Drive by '.$this->j->assessor->email, escape: false)
        ->assertSee('Google Drive connected, not checked yet');
    // Not approved yet: staff not on Zambia do not see the work in progress.
    $this->actingAs($this->j->otherStaff)->get(route('dashboard'))->assertOk()->assertDontSee('Zambia YMCA · version');
});
