<?php

use App\Actions\Evidence\UploadEvidence;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Journey;

/*
 * Finding things in Resources: filters, the table of documents, ZIP downloads, and the
 * download log that records who downloaded a movement's files.
 */

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
    $this->assessment = $this->j->assessmentAt('odp_approved');
    $path = tempnam(sys_get_temp_dir(), 'ev');
    file_put_contents($path, '%PDF-1.4 safeguarding policy');
    $this->evidence = app(UploadEvidence::class)->handle($this->j->assessor, $this->assessment, $path, 'Safeguarding Policy.pdf');
    app(UploadEvidence::class)->handle($this->j->assessor, $this->assessment, null, null, 'Website', url: 'https://zambiaymca.org');
});

it('filters by zone, health band, year and whether the ODP is validated', function () {
    $staff = $this->j->otherStaff;
    $zambiaZone = $this->j->zambia->zone_id;
    $otherZone = Zone::query()->whereKeyNot($zambiaZone)->value('id');
    $year = (int) $this->assessment->assessed_on->format('Y');
    $band = DB::table('v_movement_status')->where('movement_id', $this->j->zambia->id)->value('band_code');

    $sees = fn (array $query) => $this->actingAs($staff)->get(route('resources', $query))->assertOk();

    $sees(['zone' => $zambiaZone])->assertSee('Zambia YMCA');
    $sees(['zone' => $otherZone])->assertDontSee('Zambia YMCA')->assertSee('Nothing matches these filters.');
    $sees(['band' => $band])->assertSee('Zambia YMCA');
    $sees(['band' => $band === 'strong' ? 'atrisk' : 'strong'])->assertDontSee('Zambia YMCA');
    $sees(['year' => $year])->assertSee('Zambia YMCA');
    $sees(['year' => $year - 1])->assertDontSee('Zambia YMCA');
    $sees(['odp' => 'not_validated'])->assertSee('Zambia YMCA');
    $sees(['odp' => 'validated'])->assertDontSee('Zambia YMCA');
});

it('lists every document in a table, by type, sorted by the column chosen', function () {
    $this->actingAs($this->j->otherStaff)->get(route('resources', ['view' => 'table']))->assertOk()
        ->assertSee('OHA form')->assertSee('Report')->assertSee('ODP')->assertSee('Evidence')
        ->assertSee('Safeguarding Policy · General')->assertSee('Not validated')
        ->assertSee('aria-sort="ascending"', escape: false);

    $this->actingAs($this->j->otherStaff)->get(route('resources', ['view' => 'table', 'type' => 'evidence']))->assertOk()
        ->assertSee('Safeguarding Policy')->assertSee('2 documents')->assertDontSee('ZAM26 OHA Form.xlsx');

    $this->actingAs($this->j->otherStaff)->get(route('resources', ['view' => 'table', 'sort' => 'date', 'dir' => 'desc']))->assertOk()
        ->assertSee('aria-sort="descending"', escape: false);
});

it('downloads an assessment as one ZIP: the approved documents and the evidence, links listed', function () {
    $response = $this->actingAs($this->j->chair)->get(route('downloads.zip.assessment', $this->assessment))->assertOk();
    $zip = new ZipArchive;
    $zip->open($response->baseResponse->getFile()->getPathname());
    $names = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i));

    expect($names->first(fn ($n) => str_contains($n, 'OHA form - ')))->not->toBeNull()
        ->and($names->first(fn ($n) => str_contains($n, 'Report - version 1')))->not->toBeNull()
        ->and($names->first(fn ($n) => str_contains($n, 'ODP - version 1')))->not->toBeNull()
        ->and($names)->toContain('Zambia YMCA - Feb 2026/Evidence/General/Safeguarding Policy.pdf')
        ->and($zip->getFromName('Zambia YMCA - Feb 2026/Evidence/Links.txt'))->toContain('https://zambiaymca.org');

    // Another movement's users cannot have it.
    $ghanaNgs = User::factory()->generalSecretary($this->j->ghana)->create();
    $this->actingAs($ghanaNgs)->get(route('downloads.zip.assessment', $this->assessment))->assertForbidden();
    $this->actingAs($ghanaNgs)->get(route('downloads.zip.movement', $this->j->zambia))->assertForbidden();
    $this->actingAs($this->j->otherStaff)->get(route('downloads.zip.movement', $this->j->zambia))->assertOk()->assertDownload('zambia-ymca-resources.zip');
});

it('keeps a download log, apart from the work, in the assessment’s audit trail', function () {
    $form = $this->j->form($this->assessment)->currentUpload()->firstOrFail();
    $this->actingAs($this->j->otherStaff)->get(route('downloads.form', $form))->assertOk();
    $this->actingAs($this->j->chair)->get(route('evidence.download', $this->evidence))->assertOk();

    expect(DB::table('audit_events')->where('action', 'file.downloaded')->where('assessment_id', $this->assessment->id)->count())->toBe(2);

    $this->actingAs($this->j->admin)->get(route('assessments.show', ['assessment' => $this->assessment, 'tab' => 'audit']))->assertOk()
        ->assertSee('Download log')->assertSee('2 downloads')
        ->assertSee('Aminata Diallo')->assertSee('Safeguarding Policy.pdf');
});
