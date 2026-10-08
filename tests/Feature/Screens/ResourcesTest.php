<?php

use App\Actions\Oha\AttachDocument;
use App\Actions\Oha\UploadForm;
use App\Enums\DocumentPurpose;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Journey;

/*
 * Resources: what the Administrators have approved, movement by movement. Every AAYMCA
 * staff member sees every movement; a Board Chairperson only their own.
 */

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
});

it('lists each movement’s approved form, report and ODP for all staff', function () {
    $assessment = $this->j->assessmentAt('odp_approved');
    $form = $this->j->form($assessment)->currentUpload()->firstOrFail();

    $this->actingAs($this->j->otherStaff)->get(route('dashboard'))->assertSee('Resources')->assertSee(route('resources'), false);

    $this->actingAs($this->j->otherStaff)->get(route('resources'))
        ->assertOk()
        ->assertSee('Zambia YMCA')->assertSee('Feb 2026')
        ->assertSee('ZAM26 OHA Form.xlsx')->assertSee(route('downloads.form', $form), false)
        ->assertSee('Zambia OHA Report 2026.pdf')->assertSee('Zambia ODP 2026.pdf')
        ->assertSee('Not validated')
        // Only movements with something approved, unless all are asked for.
        ->assertDontSee('Ghana YMCA')->assertSee('With documents (1)');

    $this->actingAs($this->j->otherStaff)->get(route('resources', ['show' => 'all']))
        ->assertSee('Ghana YMCA')->assertSee('Nothing approved yet.');
});

it('shows a Board Chairperson their own movement only, including its approved form', function () {
    $assessment = $this->j->assessmentAt('report_approved');

    $this->actingAs($this->j->chair)->get(route('resources'))
        ->assertOk()
        ->assertSee('Zambia YMCA')->assertSee('ZAM26 OHA Form.xlsx')->assertSee('Zambia OHA Report 2026.pdf')
        ->assertDontSee('Ghana YMCA')->assertDontSee('Movement or country');

    // The approved form, read and downloaded from there.
    $form = $this->j->form($assessment)->currentUpload()->firstOrFail();
    $this->actingAs($this->j->chair)->get(route('downloads.form', $form))->assertOk();
    $this->actingAs($this->j->chair)->get(route('assessments.show', ['assessment' => $assessment, 'tab' => 'form', 'upload' => $form->id]))
        ->assertOk()->assertSee('Preview · ZAM26 OHA Form.xlsx');

    $this->actingAs($this->j->ghanaChair)->get(route('resources'))->assertOk()
        ->assertDontSee('Zambia YMCA')->assertSee('Ghana YMCA')->assertSee('Nothing approved yet.');
    $this->actingAs($this->j->ghanaChair)->get(route('downloads.form', $form))->assertForbidden();
});

it('keeps showing the approved form while a corrected one waits for approval', function () {
    $assessment = $this->j->assessmentAt('form_approved');
    $approved = $this->j->form($assessment)->currentUpload()->firstOrFail();
    $corrected = app(UploadForm::class)->handle($this->j->form($assessment), zambiaFormPath(), 'ZAM26 corrected.xlsx', $this->j->assessor);

    $this->actingAs($this->j->otherStaff)->get(route('resources'))
        ->assertSee('ZAM26 OHA Form.xlsx')->assertDontSee('ZAM26 corrected.xlsx');
    // The corrected upload stays with the assessors and Administrators until it is approved.
    $this->actingAs($this->j->otherStaff)->get(route('downloads.form', $corrected))->assertForbidden();
    $this->actingAs($this->j->otherStaff)->get(route('downloads.form', $approved))->assertOk();
    $this->actingAs($this->j->chair)->get(route('downloads.form', $corrected))->assertForbidden();
});

it('lists the ODP’s reference files with it, for whoever may see the approved ODP', function () {
    $assessment = $this->j->assessmentAt('odp_approved');
    $path = tempnam(sys_get_temp_dir(), 'ref');
    file_put_contents($path, '%PDF-1.4 board minutes');
    app(AttachDocument::class)->handle($this->j->odp($assessment), $path, 'Board minutes March 2026.pdf', DocumentPurpose::Reference, $this->j->assessor);

    $this->actingAs($this->j->otherStaff)->get(route('resources'))->assertSee('ODP reference files')->assertSee('Board minutes March 2026.pdf');
    $this->actingAs($this->j->chair)->get(route('resources'))->assertSee('Board minutes March 2026.pdf');
});
