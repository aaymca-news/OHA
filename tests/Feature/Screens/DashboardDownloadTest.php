<?php

use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Journey;

/*
 * The dashboards to download (decided 23 Nov 2026): a PDF to read and share, or an Excel
 * workbook, with the same figures as the screen and for the same people.
 */

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
    $this->ngs = User::factory()->generalSecretary($this->j->zambia)->create();
    $this->j->assessmentAt('closed');
});

it('downloads AAYMCA’s dashboard as a PDF or a workbook, for AAYMCA staff only', function () {
    $this->actingAs($this->j->otherStaff)->get(route('dashboard'))->assertOk()
        ->assertSee(route('dashboard.report', ['format' => 'pdf']), false)->assertSee(route('dashboard.report', ['format' => 'xlsx']), false);

    $pdf = $this->actingAs($this->j->otherStaff)->get(route('dashboard.report', ['format' => 'pdf']))->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')->assertDownload();
    expect($pdf->getContent())->toStartWith('%PDF');
    $xlsx = $this->actingAs($this->j->otherStaff)->get(route('dashboard.report', ['format' => 'xlsx', 'zone' => $this->j->zambia->zone_id]))->assertOk()->assertDownload();
    expect($xlsx->getContent())->toStartWith('PK');

    $this->actingAs($this->j->chair)->get(route('dashboard.report', ['format' => 'pdf']))->assertForbidden();
    $this->actingAs($this->j->otherStaff)->get('/dashboard/report.docx')->assertNotFound();
});

it('downloads a movement’s dashboard for its own users and AAYMCA staff, never another movement’s', function () {
    foreach ([$this->j->chair, $this->ngs, $this->j->otherStaff] as $user) {
        $pdf = $this->actingAs($user)->get(route('movements.report', [$this->j->zambia, 'pdf']))->assertOk()->assertDownload();
        expect($pdf->getContent())->toStartWith('%PDF');
        $this->actingAs($user)->get(route('movements.report', [$this->j->zambia, 'xlsx']))->assertOk()->assertDownload();
    }
    $this->actingAs($this->j->chair)->get(route('dashboard'))->assertOk()->assertSee(route('movements.report', [$this->j->zambia, 'pdf']), false);

    $this->actingAs($this->j->ghanaChair)->get(route('movements.report', [$this->j->zambia, 'pdf']))->assertForbidden();
    // A movement with no approved form has nothing to download.
    $this->actingAs($this->j->otherStaff)->get(route('movements.report', [$this->j->ghana, 'pdf']))->assertNotFound();
});
