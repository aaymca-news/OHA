<?php

use App\Support\UploadLimit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Journey;

/*
 * The upload boxes: a file dropped or chosen, the real size limit shown and enforced
 * with a plain message, and a Google Drive link that cannot be used reported against the
 * link box before anything is saved.
 */

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
});

it('shows a drop box with the real size limit on the form, report and ODP tabs', function () {
    $assessment = $this->j->assessmentAt('report_approved');

    foreach (['form' => 'name="form"', 'report' => 'name="report"', 'odp' => 'name="odp"'] as $tab => $input) {
        $this->actingAs($this->j->assessor)->get(route('assessments.show', ['assessment' => $assessment, 'tab' => $tab]))->assertOk()
            ->assertSee('fileDrop(', false)->assertSee($input, false)->assertSee('Up to '.UploadLimit::label().'.');
    }
});

it('takes the smaller of the platform’s limit and PHP’s', function () {
    config(['oha.max_upload_kb' => 100]);
    expect(UploadLimit::kb())->toBe(100)->and(UploadLimit::label())->toBe('0.1 MB');

    config(['oha.max_upload_kb' => 10_000_000]);
    expect(UploadLimit::kb())->toBeLessThan(10_000_000)
        ->and(UploadLimit::kb())->toBe(min((int) ini_get('upload_max_filesize') * 1024, (int) ini_get('post_max_size') * 1024));
});

it('refuses a file over the limit with a plain message', function () {
    $assessment = $this->j->assessmentAt('report_approved');
    config(['oha.max_upload_kb' => 1]);
    $big = UploadedFile::fake()->create('Zambia ODP 2026.pdf', 5, 'application/pdf');

    $this->actingAs($this->j->assessor)->post(route('artefacts.versions', $this->j->odp($assessment)), ['odp' => $big, 'drive_url' => Journey::DRIVE_URL, 'seen' => 0])
        ->assertSessionHasErrors(['odp' => UploadLimit::tooBig()]);
    expect($this->j->odp($assessment)->versions()->count())->toBe(0);
});

it('reports a folder link against the link box, and saves nothing', function () {
    $assessment = $this->j->assessmentAt('report_approved');
    $odp = $this->j->odp($assessment);
    $file = UploadedFile::fake()->createWithContent('Zambia ODP 2026.pdf', file_get_contents(Journey::reportFile('ODP')));

    $this->actingAs($this->j->assessor)->post(route('artefacts.versions', $odp), [
        'odp' => $file, 'drive_url' => 'https://drive.google.com/drive/folders/1AbCdEfGhIjKlMnOpQrStUvWxYz012345', 'seen' => 0,
    ])->assertSessionHasErrors(['drive_url' => 'This is a link to a folder (such as the movement’s folder in Google Drive), not to the ODP. Open the folder, open the ODP file inside it, then copy its link with Share → Copy link.']);
    expect($odp->versions()->count())->toBe(0)->and($odp->refresh()->drive_url)->toBeNull();

    // Back on the ODP tab: the message under the link box, and the link as typed.
    $odpTab = route('assessments.show', ['assessment' => $assessment, 'tab' => 'odp']);
    $this->actingAs($this->j->assessor)->from($odpTab)->followingRedirects()->post(route('artefacts.versions', $odp), [
        'odp' => $file, 'drive_url' => 'https://drive.google.com/drive/folders/1AbCdEfGhIjKlMnOpQrStUvWxYz012345', 'seen' => 0,
    ])->assertOk()->assertSee('text-error', false)->assertSee('not to the ODP')->assertSee('value="https://drive.google.com/drive/folders/1AbCdEfGhIjKlMnOpQrStUvWxYz012345"', false);

    // The file and a link to the file itself: saved.
    $this->actingAs($this->j->assessor)->post(route('artefacts.versions', $odp), ['odp' => $file, 'drive_url' => Journey::DRIVE_URL, 'seen' => 0])
        ->assertSessionHasNoErrors();
    expect($odp->versions()->count())->toBe(1);
});

it('explains a request too large for the server', function () {
    expect(view('errors.413')->render())->toContain('The file was too large')->toContain(UploadLimit::label());
});
