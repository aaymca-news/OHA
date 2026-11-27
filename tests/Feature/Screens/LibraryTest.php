<?php

use App\Actions\Evidence\UploadEvidence;
use App\Actions\Oha\UploadForm;
use App\Models\User;
use App\Support\Navigation;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Journey;

/*
 * The Library (decided 27 Nov 2026), in place of Resources: each assessment's Stage 1
 * documents (the Excel assessment form, the report and the ODP, as approved, and when) on
 * the movement's page; each movement's Health Assessment Evidence Library, named for it;
 * and the Area Resource Repository, where what movements share is open to everyone.
 */

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
    $this->ngs = User::factory()->generalSecretary($this->j->zambia)->create(['name' => 'Mwila Banda']);
    $this->evidence = function (string $title, ?User $by = null) {
        $path = tempnam(sys_get_temp_dir(), 'ev');
        file_put_contents($path, '%PDF-1.4 '.$title.' '.uniqid());

        return app(UploadEvidence::class)->handle($by ?? $this->j->assessor, $this->assessment, $path, $title.'.pdf');
    };
});

it('shows the three Stage 1 documents on the movement’s page, each with when it was approved', function () {
    $assessment = $this->j->assessmentAt('odp_approved');
    $form = $this->j->form($assessment)->currentUpload()->firstOrFail();
    $report = $this->j->report($assessment)->approvedVersion()->firstOrFail();
    $odp = $this->j->odp($assessment)->approvedVersion()->firstOrFail();
    $read = route('assessments.show', ['assessment' => $assessment, 'tab' => 'form', 'upload' => $form->id]).'#form-preview';

    // The movement's own people: the documents, their dates and downloads; never a way into the assessment's work.
    foreach ([$this->j->chair, $this->ngs] as $user) {
        $this->actingAs($user)->get(route('movements.show', $this->j->zambia))->assertOk()
            ->assertSeeInOrder(['Stage 1 documents', '1. Excel assessment form', '2. Report', '3. ODP'])
            ->assertSee('ZAM26 OHA Form.xlsx')->assertSee('Zambia OHA Report 2026.pdf · version 1')->assertSee('Zambia ODP 2026.pdf · version 1')
            ->assertSee('Approved '.$form->approved_at->asDay())->assertSee('Approved '.$odp->approved_at->asDay())
            ->assertSee(route('downloads.form', $form))->assertSee(route('downloads.document', $report))->assertSee(route('downloads.document', $odp))
            ->assertSee('Not validated')->assertSee('Stage 1 under way')
            ->assertDontSee($read);
    }

    // AAYMCA staff: the same, and each one to read in its assessment.
    $this->actingAs($this->j->otherStaff)->get(route('movements.show', $this->j->zambia))->assertOk()
        ->assertSee('Stage 1 documents')->assertSee(route('downloads.form', $form))->assertSee($read);

    // Closed: when Stage 1 closed, and the follow-ups began.
    $this->j->close($assessment);
    $this->actingAs($this->j->chair)->get(route('movements.show', $this->j->zambia))
        ->assertSee('Stage 1 closed '.$assessment->refresh()->closed_at->asDay().' · the follow-ups began');
});

it('shows only what is approved: nothing of a document still in the works', function () {
    $assessment = $this->j->assessmentAt('form_approved');
    $approved = $this->j->form($assessment)->currentUpload()->firstOrFail();
    $corrected = app(UploadForm::class)->handle($this->j->form($assessment), zambiaFormPath(), 'ZAM26 corrected.xlsx', $this->j->assessor);

    $this->actingAs($this->j->chair)->get(route('movements.show', $this->j->zambia))->assertOk()
        ->assertSee('ZAM26 OHA Form.xlsx')->assertDontSee('ZAM26 corrected.xlsx')->assertSee('Not approved yet');
    $this->actingAs($this->j->otherStaff)->get(route('downloads.form', $corrected))->assertForbidden();
    $this->actingAs($this->j->chair)->get(route('downloads.form', $corrected))->assertForbidden();
    $this->actingAs($this->j->chair)->get(route('downloads.form', $approved))->assertOk();
    $this->actingAs($this->j->ghanaChair)->get(route('downloads.form', $approved))->assertForbidden();
});

it('opens each movement’s evidence library from the top of its page, named for it, not from the menu', function () {
    $this->assessment = $this->j->assessmentAt('odp_approved');
    ($this->evidence)('Board minutes March 2026');
    $library = 'Zambia YMCA Health Assessment Evidence Library';
    $menu = fn (User $user) => collect(app(Navigation::class)->for($user))->pluck('route')->all();

    // At the top of the movement's page, beside Download, for its own people and AAYMCA staff alike.
    foreach ([$this->j->chair, $this->ngs, $this->j->otherStaff] as $user) {
        $this->actingAs($user)->get(route('movements.show', $this->j->zambia))->assertOk()
            ->assertSee('title="'.$library.'"', false)->assertSee('folder_open</span>Evidence Library', false)
            ->assertSee(route('evidence.index', $this->j->zambia));
        expect($menu($user))->not->toContain('evidence.index')->toContain('resource-repository');
    }

    // The library, named for its movement, leads back to the movement's page.
    $this->actingAs($this->j->chair)->get(route('evidence.index', $this->j->zambia))->assertOk()
        ->assertSee('<title>'.$library, false)->assertSee('Board minutes March 2026')
        ->assertSee(route('movements.show', $this->j->zambia));

    // The old Resources address: to their library, or for AAYMCA staff to the National Movements.
    $this->actingAs($this->ngs)->get(route('libraries'))->assertRedirect(route('evidence.index', $this->j->zambia));
    $this->actingAs($this->j->otherStaff)->get(route('libraries'))->assertRedirect(route('movements.index'));
});

it('opens the Area Resource Repository to everyone: empty until something is shared, then with the movement it came from', function () {
    $this->assessment = $this->j->assessmentAt('odp_approved');
    $policy = ($this->evidence)('Safeguarding Policy', $this->ngs);

    $this->actingAs($this->j->ghanaChair)->get(route('resource-repository'))->assertOk()
        ->assertSee('Nothing has been shared yet.')->assertDontSee('Safeguarding Policy');

    $this->actingAs($this->ngs)->put(route('evidence.share', $policy), ['shared' => 1])->assertSessionHasNoErrors();

    // Every movement sees it, its own too, and AAYMCA staff; by area, with where it came from.
    foreach ([$this->j->ghanaChair, $this->j->chair, $this->j->otherStaff] as $user) {
        $this->actingAs($user)->get(route('resource-repository'))->assertOk()
            ->assertSee('Safeguarding Policy')->assertSee('Shared from')->assertSee('Zambia YMCA')->assertSee('General')
            ->assertSee('1 document shared by 1 National Movement');
    }
    $this->actingAs($this->j->ghanaChair)->get(route('evidence.download', $policy))->assertOk();
    $this->actingAs($this->j->ghanaChair)->get(route('resource-repository', ['area' => 'financial']))->assertOk()
        ->assertSee('Nothing shared matches these filters.');
});

it('lets the National General Secretary and AAYMCA staff share evidence, never the Board Chairperson', function () {
    $this->assessment = $this->j->assessmentAt('odp_approved');
    $minutes = ($this->evidence)('Board minutes');

    $this->actingAs($this->j->chair)->put(route('evidence.share', $minutes), ['shared' => 1])->assertSessionHasErrors('action');
    expect($minutes->refresh()->isShared())->toBeFalse();

    foreach ([$this->j->otherStaff, $this->j->assessor, $this->j->admin, $this->j->superAdmin, $this->ngs] as $user) {
        $this->actingAs($user)->put(route('evidence.share', $minutes), ['shared' => 1])->assertSessionHasNoErrors();
        expect($minutes->refresh()->isShared())->toBeTrue();
        $this->actingAs($user)->put(route('evidence.share', $minutes), ['shared' => 0])->assertSessionHasNoErrors();
    }

    // The "share" box when adding: for those who may share.
    $this->actingAs($this->j->otherStaff)->get(route('evidence.index', $this->j->zambia))->assertSee('Share this in the Area Resource Repository');
    $this->actingAs($this->j->chair)->get(route('evidence.index', $this->j->zambia))->assertDontSee('Share this in the Area Resource Repository');
});
