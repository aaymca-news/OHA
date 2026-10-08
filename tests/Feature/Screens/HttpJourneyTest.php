<?php

use App\Enums\ArtefactState;
use App\Models\ArtefactStatus;
use App\Models\Assessment;
use App\Models\MovementStatus;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Journey;

/*
 * The whole of Stage 1 for Zambia, driven only through the screens' own forms.
 */

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
});

function reportUpload(string $content, string $name = 'Zambia OHA Report 2026.pdf'): UploadedFile
{
    return new UploadedFile(Journey::reportFile($content), $name, null, null, true);
}

it('takes Zambia from a new assessment to a signed ODP through the screens', function () {
    $j = $this->j;

    // The assessor opens the assessment from the movement's page and uploads the form.
    $this->actingAs($j->assessor)->post(route('assessments.store', $j->zambia), ['period_label' => 'Feb 2026', 'period_start' => '2026-02'])->assertRedirect();
    $assessment = Assessment::query()->latest('id')->firstOrFail();
    $form = $j->form($assessment);

    $this->actingAs($j->assessor)->post(route('artefacts.upload', $form), [
        'form' => new UploadedFile(zambiaFormPath(), 'ZAM26 OHA Form.xlsx', null, null, true),
    ])->assertSessionHas('status', fn ($s) => str_contains($s, '3 item(s) are missing'));

    $this->actingAs($j->assessor)->post(route('artefacts.submit', $form), ['acknowledge_gaps' => '1', 'reason' => 'Following up.'])
        ->assertSessionHas('status', 'Submitted for approval. The Administrators have been told.');

    // An Administrator approves; Zambia is rated from that moment and the form is visible to all staff.
    $this->actingAs($j->admin)->post(route('artefacts.approve', $form))->assertSessionHasNoErrors();
    expect(MovementStatus::query()->where('slug', 'zambia')->value('band_code'))->toBe('developing');
    $this->actingAs($j->otherStaff)->get(route('assessments.show', ['assessment' => $assessment, 'tab' => 'form']))->assertSee('Score check');

    // Report: uploaded and saved, previewed, replaced by a second version, submitted, approved.
    $report = $j->report($assessment);
    $reportTab = route('assessments.show', ['assessment' => $assessment, 'tab' => 'report']);
    $this->actingAs($j->assessor)->get($reportTab)->assertSee('Upload the report')->assertSee('No report has been uploaded yet.');
    $this->actingAs($j->assessor)->post(route('artefacts.versions', $report), ['report' => reportUpload('first')])
        ->assertSessionHas('status', fn ($s) => str_starts_with($s, 'Saved as version 1. The report check flagged'));
    $this->actingAs($j->assessor)->post(route('artefacts.versions', $report), ['report' => reportUpload('second'), 'note' => 'Corrected the staff figures.'])
        ->assertSessionHas('status', fn ($s) => str_starts_with($s, 'Saved as version 2.'));

    $version = $report->latestVersion()->firstOrFail();
    $this->actingAs($j->assessor)->get($reportTab)->assertOk()
        ->assertSee('Version 2 · Zambia OHA Report 2026.pdf')->assertSee(route('downloads.preview', $version), false)
        ->assertSee('Corrected the staff figures.')->assertSee('Submit version 2 for approval');
    // Not approved yet: other staff and the Chairperson cannot see it.
    $this->actingAs($j->otherStaff)->get($reportTab)->assertDontSee('Version 2 ·');

    $this->actingAs($j->assessor)->post(route('artefacts.submit', $report))->assertSessionHasNoErrors();
    $this->actingAs($j->secondAdmin)->get($reportTab)->assertSee('Your approval · version 2');
    $this->actingAs($j->secondAdmin)->post(route('artefacts.approve', $report))
        ->assertSessionHas('status', 'Approved. All AAYMCA staff and the Board Chairperson can now see this version of the report.');

    $this->actingAs($j->chair)->get($reportTab)->assertOk()->assertSee('Version 2 · Zambia OHA Report 2026.pdf')
        ->assertDontSee('Sign and validate')->assertDontSee('Upload a new version');

    // ODP: uploaded with its Google Drive link, approved, signed by the Chairperson.
    $odp = $j->odp($assessment);
    $odpTab = route('assessments.show', ['assessment' => $assessment, 'tab' => 'odp']);
    $this->actingAs($j->assessor)->get($odpTab)->assertSee('Upload the ODP')->assertSee('Link to the ODP in Google Drive');
    $this->actingAs($j->assessor)->post(route('artefacts.versions', $odp), [
        'odp' => UploadedFile::fake()->createWithContent('Zambia ODP 2026.pdf', file_get_contents(Journey::reportFile('Zambia ODP'))),
    ])->assertSessionHasErrors(['drive_url' => 'Add the link to the ODP in Google Drive, so everyone works on the same document.']);
    $this->actingAs($j->assessor)->post(route('artefacts.versions', $odp), [
        'odp' => UploadedFile::fake()->createWithContent('Zambia ODP 2026.pdf', file_get_contents(Journey::reportFile('Zambia ODP'))),
        'drive_url' => Journey::DRIVE_URL,
    ])->assertSessionHas('status', 'Saved as version 1. Preview it, then submit it for approval when it is ready.');
    $this->actingAs($j->assessor)->get($odpTab)->assertSee('Open in Google Drive')->assertSee('Continue in Google Drive')
        ->assertSee('By upload only, for now.');
    $this->actingAs($j->assessor)->post(route('artefacts.submit', $odp));
    $this->actingAs($j->superAdmin)->post(route('artefacts.approve', $odp));

    // Approved: the Chairperson reads it and signs it, without the live Google Drive link.
    $this->actingAs($j->chair)->get($odpTab)
        ->assertOk()->assertSee('Sign to validate the ODP')->assertSee('Version 1 · Zambia ODP 2026.pdf')
        ->assertDontSee('Google Drive document')->assertDontSee('docs.google.com');
    $this->actingAs($j->otherStaff)->get($odpTab)->assertOk()->assertSee('Version 1 · Zambia ODP 2026.pdf')->assertDontSee('Upload a new version');
    $this->actingAs($j->chair)->post(route('artefacts.sign', $odp), [
        'signature' => 'Naledi Moyo', 'confirm' => '1', 'comment' => 'Well done.',
    ])->assertSessionHas('status', 'Signed. The ODP is now validated by the board.');

    expect($j->odp($assessment)->state)->toBe(ArtefactState::Approved)
        ->and(ArtefactStatus::query()->findOrFail($report->id)->validated)->toBeFalse()
        ->and(ArtefactStatus::query()->findOrFail($odp->id)->validated)->toBeTrue()
        ->and(MovementStatus::query()->where('slug', 'zambia')->value('has_odp'))->toBeTrue();

    // The typed signature shows on the ODP for everyone who may see it.
    $this->actingAs($j->otherStaff)->get($odpTab)->assertSee('Validated by the board')->assertSee('Naledi Moyo');
});

it('lets the assessor upload a new version after approval, which goes back for approval', function () {
    $assessment = $this->j->assessmentAt('report_approved');
    $report = $this->j->report($assessment);
    $reportTab = route('assessments.show', ['assessment' => $assessment, 'tab' => 'report']);

    $this->actingAs($this->j->assessor)->get($reportTab)->assertSee('Upload a new version')
        ->assertSee('A new version goes back to the Administrators for approval');
    $this->actingAs($this->j->assessor)->post(route('artefacts.versions', $report), ['report' => reportUpload('after approval')])
        ->assertSessionHas('status', fn ($s) => str_starts_with($s, 'Saved as version 2.'));

    $this->actingAs($this->j->otherStaff)->get($reportTab)->assertOk()
        ->assertSee('Version 1 · Zambia OHA Report 2026.pdf')->assertDontSee('Version 2');
    $this->actingAs($this->j->assessor)->get($reportTab)->assertSee('Staff and the Board Chairperson see the last approved version until the new one is approved.');
});

it('refuses a report that is not Word or PDF, saying why', function () {
    $report = $this->j->report($this->j->assessmentAt('form_approved'));

    $this->actingAs($this->j->assessor)->post(route('artefacts.versions', $report), ['report' => reportUpload('x', 'report.xlsx')])
        ->assertSessionHasErrors(['action' => 'The report must be a Word (.docx) or PDF file.']);
});

it('explains a refused step instead of failing', function () {
    $assessment = $this->j->assessmentAt('form_submitted');

    $this->actingAs($this->j->assessor)->from(route('assessments.show', $assessment))
        ->post(route('artefacts.approve', $this->j->form($assessment)))
        ->assertRedirect(route('assessments.show', $assessment))
        ->assertSessionHasErrors(['action' => 'Only an Administrator approves.']);

    expect($this->j->form($assessment)->state)->toBe(ArtefactState::PendingApproval);
});

it('refuses a signature without the confirmation', function () {
    $odp = $this->j->odp($this->j->assessmentAt('odp_approved'));

    $this->actingAs($this->j->chair)->post(route('artefacts.sign', $odp), ['signature' => Journey::SIGNATURE])
        ->assertSessionHasErrors(['confirm' => 'Tick the box to confirm you validate this document.']);
});

it('lets the Administrators assign assessors from the movement page, and not staff', function () {
    $this->actingAs($this->j->admin)->put(route('movements.assessors', $this->j->ghana), ['assessor_ids' => [$this->j->otherStaff->id]])
        ->assertSessionHasNoErrors();
    expect($this->j->otherStaff->canAssess($this->j->ghana))->toBeTrue();

    $this->actingAs($this->j->otherStaff)->from(route('movements.show', $this->j->ghana))
        ->put(route('movements.assessors', $this->j->ghana), ['assessor_ids' => [$this->j->otherStaff->id]])
        ->assertSessionHasErrors(['action' => 'Only the Administrators assign staff to assess a movement.']);
});

it('lets assessors resolve a gap and manage the timeline from the page', function () {
    $assessment = $this->j->assessmentAt('form_uploaded');
    $gap = $this->j->form($assessment)->currentUpload()->first()->findings()->where('ref', 'q:Q246')->firstOrFail();

    $this->actingAs($this->j->assessor)->post(route('findings.resolve', $gap), ['note' => 'Confirmed by email.'])->assertSessionHasNoErrors();
    $this->actingAs($this->j->assessor)->put(route('timeline.gate', $assessment), ['gate' => 'form', 'due_on' => '2026-12-01'])->assertSessionHasNoErrors();
    $this->actingAs($this->j->assessor)->post(route('timeline.steps.store', $assessment), ['label' => 'Field visit', 'due_on' => '2026-11-10'])->assertSessionHasNoErrors();

    $this->actingAs($this->j->assessor)->get(route('assessments.show', ['assessment' => $assessment, 'tab' => 'timeline']))
        ->assertSee('1 Dec 2026 (set by hand)')->assertSee('Field visit');
    expect($gap->refresh()->resolved_note)->toBe('Confirmed by email.');
});

it('shows each person their notifications and marks them read', function () {
    Notification::swap(new ChannelManager(app()));
    $this->j->assessmentAt('form_submitted');

    $this->actingAs($this->j->admin)->get(route('notifications.index'))->assertOk()->assertSee('Approval needed: OHA form, Zambia YMCA');
    $this->actingAs($this->j->admin)->post(route('notifications.read'))->assertSessionHasNoErrors();

    expect($this->j->admin->unreadNotifications()->count())->toBe(0);
});
