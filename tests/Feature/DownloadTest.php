<?php

use App\Actions\Oha\OpenAssessment;
use App\Actions\Oha\UploadForm;
use App\Actions\Oha\UploadReportVersion;
use App\Enums\ArtefactState;
use App\Models\Movement;
use App\Models\User;
use Database\Seeders\MovementsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Journey;

beforeEach(function () {
    Storage::fake('oha');
    $this->seed(MovementsSeeder::class);

    $this->zambia = Movement::query()->where('slug', 'zambia')->firstOrFail();
    $this->staff = User::factory()->create();
    $this->staff->assignedMovements()->attach($this->zambia);
    $this->otherStaff = User::factory()->create();
    $this->admin = User::factory()->admin()->create();
    $this->chair = User::factory()->chair($this->zambia)->create();

    $this->assessment = app(OpenAssessment::class)->handle($this->staff, $this->zambia, 'Feb 2026', Carbon::parse('2026-02-01'));
    $this->upload = app(UploadForm::class)->handle($this->assessment->form()->firstOrFail(), zambiaFormPath(), 'ZAM26 OHA Form.xlsx', $this->staff);
    $this->assessment->form()->firstOrFail()->update(['state' => ArtefactState::Approved]);

    $this->report = $this->assessment->report()->firstOrFail();
    $this->version = app(UploadReportVersion::class)->handle($this->report, Journey::reportFile('report bytes'), 'Zambia OHA Report 2026.pdf', $this->staff);
    $this->approve = function () {
        $this->report->update(['state' => ArtefactState::Approved]);
        $this->version->update(['approved_by' => $this->admin->id, 'approved_at' => now()]);
    };
});

it('lets Secretariat staff download the uploaded form under its original name', function () {
    $this->actingAs($this->staff)
        ->get(route('downloads.form', $this->upload))
        ->assertOk()
        ->assertDownload('ZAM26 OHA Form.xlsx');
});

it('keeps the raw form from the Board Chairperson', function () {
    ($this->approve)();

    $this->actingAs($this->chair)->get(route('downloads.form', $this->upload))->assertForbidden();
});

it('shows a report version in progress only to the assessors and the Administrators', function () {
    $this->actingAs($this->staff)->get(route('downloads.document', $this->version))->assertOk()->assertDownload('Zambia OHA Report 2026.pdf');
    $this->actingAs($this->admin)->get(route('downloads.document', $this->version))->assertOk();
    $this->actingAs($this->otherStaff)->get(route('downloads.document', $this->version))->assertForbidden();
    $this->actingAs($this->chair)->get(route('downloads.document', $this->version))->assertForbidden();
});

it('lets all staff and the Board Chairperson download the report once a version is approved', function () {
    ($this->approve)();

    $this->actingAs($this->otherStaff)->get(route('downloads.document', $this->version))->assertOk();
    $this->actingAs($this->chair)->get(route('downloads.document', $this->version))
        ->assertOk()
        ->assertDownload('Zambia OHA Report 2026.pdf');
});

it('previews a PDF in the page rather than saving it', function () {
    $response = $this->actingAs($this->staff)->get(route('downloads.preview', $this->version))->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('inline');
});

it('previews a Word report as the Word file, for the page to draw', function () {
    $word = app(UploadReportVersion::class)->handle($this->report, Journey::reportFile('as word'), 'Zambia OHA Report 2026.docx', $this->staff);

    $response = $this->actingAs($this->staff)->get(route('downloads.preview', $word))->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/vnd.openxmlformats-officedocument.wordprocessingml.document')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('inline');
});

it('keeps another movement’s Chairperson out entirely', function () {
    ($this->approve)();
    $ghanaChair = User::factory()->chair(Movement::query()->where('slug', 'ghana')->firstOrFail())->create();

    $this->actingAs($ghanaChair)->get(route('downloads.document', $this->version))->assertForbidden();
    $this->actingAs($ghanaChair)->get(route('downloads.preview', $this->version))->assertForbidden();
});

it('offers the blank OHA form to signed-in users', function () {
    $this->actingAs($this->staff)->get(route('downloads.blank-form'))
        ->assertOk()
        ->assertDownload('YMCA OHA Form 2026 (blank).xlsx');
});

it('sends guests away from every download', function () {
    $this->get(route('downloads.form', $this->upload))->assertRedirect(route('login'));
    $this->get(route('downloads.document', $this->version))->assertRedirect(route('login'));
    $this->get(route('downloads.preview', $this->version))->assertRedirect(route('login'));
});
