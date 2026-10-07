<?php

use App\Actions\Oha\ApproveArtefact;
use App\Actions\Oha\SubmitForApproval;
use App\Actions\Oha\UploadOdpVersion;
use App\Actions\Oha\UploadReportVersion;
use App\Enums\DocumentFormat;
use App\Exceptions\WorkflowRuleBroken;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Journey;
use Tests\Support\OdpWorkbook;

/*
 * AAYMCA's ODP template is an Excel workbook, written together as a Google Sheet. The
 * ODP is therefore uploaded as Excel too (Word and PDF still work), linked to its
 * Google Sheet, and shown on the page as a table.
 */

const SHEET_URL = 'https://docs.google.com/spreadsheets/d/1ZaMbIaOdP2026xYzAbCdEfGhIjKlMnOpQr/edit?gid=0#gid=0';

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
    $this->upload = fn ($assessment, string $path, ?string $link = SHEET_URL, $by = null) => app(UploadOdpVersion::class)
        ->handle($this->j->odp($assessment), $path, 'Zambia YMCA Organisational Development Plan.xlsx', $by ?? $this->j->assessor, null, $link);
    $this->tab = fn ($assessment) => route('assessments.show', ['assessment' => $assessment, 'tab' => 'odp']);
});

it('takes the ODP as an Excel workbook linked to its Google Sheet, and shows it as a table', function () {
    $assessment = $this->j->assessmentAt('report_uploaded');

    $version = ($this->upload)($assessment, OdpWorkbook::make());
    $odp = $this->j->odp($assessment);

    expect($version->format)->toBe(DocumentFormat::Xlsx)
        ->and($odp->drive_file_id)->toBe('1ZaMbIaOdP2026xYzAbCdEfGhIjKlMnOpQr')
        ->and($odp->drive_url)->toBe(SHEET_URL);

    $this->actingAs($this->j->assessor)->get(($this->tab)($assessment))->assertOk()
        ->assertSee('ORGANISATIONAL DEVELOPMENT PLAN')
        ->assertSee('STRATEGIC PRIORITY ONE (1):')
        ->assertSee('Develop a fundraising strategy')
        ->assertSee('General Secretary')
        ->assertSee('Continue in Google Drive')
        // A browser cannot open a workbook itself: no "open in a new tab" for it.
        ->assertDontSee(route('downloads.preview', $version));
});

it('takes it through approval and the Chairperson’s signature like any other version', function () {
    $assessment = $this->j->assessmentAt('report_approved');
    $version = ($this->upload)($assessment, OdpWorkbook::make());

    app(SubmitForApproval::class)->handle($this->j->odp($assessment), $this->j->assessor);
    app(ApproveArtefact::class)->handle($this->j->odp($assessment), $this->j->admin);
    $signature = $this->j->sign($this->j->odp($assessment));

    expect($signature->document_id)->toBe($version->id)
        ->and($signature->document_sha256)->toBe($version->sha256);

    // The Chairperson reads the approved workbook on the page, without the live Google Sheet.
    $this->actingAs($this->j->chair)->get(($this->tab)($assessment))->assertOk()
        ->assertSee('Develop a fundraising strategy')
        ->assertDontSee('docs.google.com');
});

it('keeps the report to Word and PDF', function () {
    $assessment = $this->j->assessmentAt('form_uploaded');

    expect(fn () => app(UploadReportVersion::class)->handle($this->j->report($assessment), OdpWorkbook::make(), 'report.xlsx', $this->j->assessor))
        ->toThrow(WorkflowRuleBroken::class, 'The report must be a Word (.docx) or PDF file.');
});

it('says so when an Excel file cannot be shown, instead of failing the page', function () {
    $assessment = $this->j->assessmentAt('report_uploaded');
    $broken = tempnam(sys_get_temp_dir(), 'bad');
    file_put_contents($broken, 'not really a workbook');

    ($this->upload)($assessment, $broken);

    $this->actingAs($this->j->assessor)->get(($this->tab)($assessment))->assertOk()
        ->assertSee('This Excel file could not be shown here. Download it to read it.');
});

it('refuses files the ODP cannot be', function () {
    $assessment = $this->j->assessmentAt('report_uploaded');

    expect(fn () => app(UploadOdpVersion::class)->handle($this->j->odp($assessment), Journey::reportFile('x'), 'plan.csv', $this->j->assessor, null, SHEET_URL))
        ->toThrow(WorkflowRuleBroken::class, 'The ODP must be an Excel (.xlsx), Word (.docx) or PDF file.');
});
