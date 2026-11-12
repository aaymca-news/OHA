<?php

use App\Actions\Oha\EditReportDraft;
use App\Actions\Oha\MakeReportFromDraft;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\AuditEvent;
use App\Models\ReportDraft;
use App\Models\User;
use App\Notifications\WorkflowNotice;
use App\Oha\Report\ReadReport;
use App\Oha\Report\ReportChecker;
use App\Support\ReplaceGuard;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Journey;

/*
 * The report drafted from the OHA form and corrected on screen: started from the Report
 * tab by whoever may upload the report, each section saved, rewritten, added or removed,
 * its facts brought up to date, and two assessors kept from overwriting each other.
 */

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
    $this->kofi = User::factory()->create(['name' => 'Kofi Mensah']);
    $this->kofi->assignedMovements()->attach($this->j->zambia);
    $this->assessment = $this->j->assessmentAt('form_uploaded');
    $this->report = $this->j->report($this->assessment);
    $this->edit = app(EditReportDraft::class);
    $this->draft = fn () => ReportDraft::query()->where('artefact_id', $this->report->id)->firstOrFail();
});

it('offers the draft on the Report tab, and drafts the report from the OHA form', function () {
    $this->actingAs($this->j->assessor)->get(route('assessments.show', ['assessment' => $this->assessment, 'tab' => 'report']))
        ->assertSee('Let the platform draft the report')->assertSee('Generate a draft report');

    $this->actingAs($this->j->assessor)->post(route('report-draft.start', $this->report))
        ->assertRedirect(route('report-draft.show', $this->report));

    $this->actingAs($this->j->assessor)->get(route('report-draft.show', $this->report))->assertOk()
        ->assertSee('ORGANISATIONAL OVERVIEW')->assertSee('1. Financial Stability')->assertSee('Nothing left to complete')
        ->assertSee('The form’s answers behind this section', false);
    $this->actingAs($this->j->assessor)->get(route('assessments.show', ['assessment' => $this->assessment, 'tab' => 'report']))
        ->assertSee('Open the draft');

    expect(AuditEvent::query()->where('action', 'report.draft_started')->where('actor_id', $this->j->assessor->id)->exists())->toBeTrue();
    Notification::assertSentTo($this->kofi, WorkflowNotice::class, fn (WorkflowNotice $n, array $channels) => str_starts_with($n->subject, 'Report draft:') && $channels === ['database']);
});

it('keeps the draft to those who may upload the report', function () {
    $this->edit->start($this->report, $this->j->assessor);

    foreach ([$this->j->otherStaff, $this->j->chair] as $user) {
        $this->actingAs($user)->get(route('report-draft.show', $this->report))->assertForbidden();
    }
    $this->actingAs($this->j->otherStaff)->post(route('report-draft.start', $this->report))->assertSessionHasErrors('action');
    $this->actingAs($this->j->otherStaff)->get(route('assessments.show', ['assessment' => $this->assessment, 'tab' => 'report']))
        ->assertDontSee('Generate a draft report');
});

it('saves a corrected section, says who saved it, and never overwrites a colleague’s newer save', function () {
    $this->edit->start($this->report, $this->j->assessor);

    $this->actingAs($this->kofi)->put(route('report-draft.save', ['artefact' => $this->report, 'key' => 'overview']), [
        'text' => 'Zambia YMCA is a registered association.', 'seen' => '',
    ])->assertSessionHasNoErrors();
    $overview = ($this->draft)()->section('overview');
    expect($overview['text'])->toBe('Zambia YMCA is a registered association.')
        ->and($overview['saved_by_name'])->toBe('Kofi Mensah');

    // Tendai's page was opened before Kofi saved: his save stops, and names Kofi.
    $this->actingAs($this->j->assessor)->put(route('report-draft.save', ['artefact' => $this->report, 'key' => 'overview']), ['text' => 'Something else.', 'seen' => ''])
        ->assertSessionHasErrors(['action' => '“ORGANISATIONAL OVERVIEW” was saved by Kofi Mensah at '.now()->format('H:i').', after you opened the page. Reload to see their change before saving yours.']);
    // Another section is not held up.
    $this->actingAs($this->j->assessor)->put(route('report-draft.save', ['artefact' => $this->report, 'key' => 'risks']), ['items' => "• First risk\n\nSecond risk", 'seen' => ''])
        ->assertSessionHasNoErrors();
    expect(($this->draft)()->section('risks')['items'])->toBe(['First risk', 'Second risk'])
        ->and(($this->draft)()->section('overview')['text'])->toBe('Zambia YMCA is a registered association.');
});

it('keeps the score table to the form', function () {
    $this->edit->start($this->report, $this->j->assessor);

    expect(fn () => $this->edit->save(($this->draft)(), 'scores', ['text' => 'x'], null, $this->j->assessor))
        ->toThrow(WorkflowRuleBroken::class, 'comes from the OHA form');
});

it('rewrites a section from the form, and adds and removes the assessors’ own sections', function () {
    $this->edit->start($this->report, $this->j->assessor);
    $generated = ($this->draft)()->section('category:governance');
    $this->edit->save(($this->draft)(), 'category:governance', ['text' => 'Shortened.', 'items' => 'One thing'], null, $this->j->assessor);

    $this->actingAs($this->j->assessor)->post(route('report-draft.rewrite', ['artefact' => $this->report, 'key' => 'category:governance']))->assertSessionHasNoErrors();
    expect(($this->draft)()->section('category:governance')['text'])->toBe($generated['text']);

    $this->actingAs($this->j->assessor)->post(route('report-draft.add', $this->report), ['title' => 'Programmes and Projects', 'after' => 'opportunities'])->assertSessionHasNoErrors();
    $keys = array_column(($this->draft)()->sections, 'key');
    $added = $keys[array_search('opportunities', $keys, true) + 1];
    expect($added)->toStartWith('custom:')
        ->and(($this->draft)()->section($added)['title'])->toBe('Programmes and Projects');

    expect(fn () => $this->edit->remove(($this->draft)(), 'category:staff', $this->j->assessor))->toThrow(WorkflowRuleBroken::class, 'stay');
    $this->actingAs($this->j->assessor)->delete(route('report-draft.remove', ['artefact' => $this->report, 'key' => $added]))->assertSessionHasNoErrors();
    expect(($this->draft)()->section($added))->toBeNull();
});

it('starts again only when asked, and brings the facts up to date after a new form, keeping the corrections', function () {
    $this->edit->start($this->report, $this->j->assessor);
    expect(fn () => $this->edit->start($this->report, $this->kofi))->toThrow(WorkflowRuleBroken::class, 'already started');

    $this->edit->save(($this->draft)(), 'overview', ['text' => 'Our own overview.'], null, $this->j->assessor);
    // As if the draft had been made from an earlier form, with other scores.
    $draft = ($this->draft)();
    $draft->update([
        'form_upload_id' => null,
        'sections' => array_map(fn ($s) => $s['key'] === 'scores' ? ['rows' => [], 'overall' => ['points' => 1, 'max' => 84, 'pct' => 1.19, 'band_label' => 'Critical']] + $s : $s, $draft->sections),
    ]);
    expect($this->edit->formChanged($draft->refresh()))->toBeTrue();
    $this->actingAs($this->j->assessor)->get(route('report-draft.show', $this->report))->assertSee('The OHA form has changed since this draft was made.');

    $this->actingAs($this->j->assessor)->post(route('report-draft.facts', $this->report))->assertSessionHasNoErrors();
    $draft = ($this->draft)();
    expect($this->edit->formChanged($draft))->toBeFalse()
        ->and(collect($draft->section('scores')['rows'])->sum('points'))->toEqual(58)
        ->and($draft->section('overview')['text'])->toBe('Our own overview.');

    $this->actingAs($this->kofi)->post(route('report-draft.start', $this->report), ['again' => 1])->assertSessionHasNoErrors();
    expect(($this->draft)()->section('overview')['text'])->not->toBe('Our own overview.')
        ->and(AuditEvent::query()->where('action', 'report.draft_restarted')->exists())->toBeTrue();
});

it('discards a draft, and is closed once the ODP is signed', function () {
    $this->edit->start($this->report, $this->j->assessor);
    $this->actingAs($this->j->assessor)->delete(route('report-draft.destroy', $this->report))->assertSessionHasNoErrors();
    expect(ReportDraft::query()->count())->toBe(0);

    $signed = $this->j->assessmentAt('closed');
    expect(fn () => $this->edit->start($this->j->report($signed), $this->j->assessor))->toThrow(WorkflowRuleBroken::class, 'frozen');
});

it('makes the draft into the Word report on the official template, saved as the next version, once every section is confirmed read', function () {
    $this->edit->start($this->report, $this->j->assessor);

    $this->actingAs($this->j->assessor)->post(route('report-draft.make', $this->report), ['read_every_section' => 0, 'seen' => 0])
        ->assertSessionHasErrors(['action' => 'Tick the box to confirm you have read and checked every section.']);
    expect($this->report->versions()->count())->toBe(0);

    $this->actingAs($this->j->assessor)->post(route('report-draft.make', $this->report), ['read_every_section' => 1, 'seen' => 0])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', fn ($s) => str_starts_with($s, 'The Word report was created and saved as version 1. The report check found nothing missing.'));

    $version = $this->report->versions()->firstOrFail();
    $read = ReadReport::fromArray($version->extracted);
    expect($version->original_name)->toBe('Zambia YMCA OHA Report February 2026.docx')
        ->and($version->format->value)->toBe('docx')
        ->and($version->note)->toBeNull()
        ->and($read->text())->toContain('AFRICA ALLIANCE OF YMCAs')
        ->and($read->text())->toContain('Report by: Kofi Mensah and Tendai Moyo, AAYMCA')
        ->and($read->text())->toContain('Developing')
        ->and($read->text())->not->toContain('What goes here')
        ->and(app(ReportChecker::class)->check($read, $this->assessment)['findings'])->toBe([])
        // Nothing marks it as drafted by the platform.
        ->and(json_encode($version->getAttributes()))->not->toContain('draft');
});

it('asks for the same confirmation to replace a colleague’s version as an upload does', function () {
    $this->j->uploadReport($this->assessment, 'Kofi’s version', $this->kofi);
    $this->edit->start($this->report, $this->j->assessor);
    $seen = ReplaceGuard::current($this->report)->id;

    $this->actingAs($this->j->assessor)->post(route('report-draft.make', $this->report), ['read_every_section' => 1, 'seen' => $seen, 'replace' => 1])
        ->assertSessionHasErrors('action');
    $this->actingAs($this->j->assessor)->post(route('report-draft.make', $this->report), ['read_every_section' => 1, 'seen' => $seen, 'replace' => 1, 'replace_reason' => 'The drafted report, corrected.'])
        ->assertSessionHasNoErrors();
    expect($this->report->versions()->count())->toBe(2)
        ->and(AuditEvent::query()->where('action', 'report.version_uploaded')->where('actor_id', $this->j->assessor->id)->value('payload')['reason'])->toBe('The drafted report, corrected.');
});

it('flags any "To complete" left in a report', function () {
    $this->edit->start($this->report, $this->j->assessor);
    $this->edit->save(($this->draft)(), 'overview', ['text' => 'Zambia YMCA is registered at [To complete: the address].'], null, $this->j->assessor);

    app(MakeReportFromDraft::class)->handle(($this->draft)(), $this->j->assessor, true);
    $read = ReadReport::fromArray($this->report->versions()->firstOrFail()->extracted);

    expect(collect(app(ReportChecker::class)->check($read, $this->assessment)['findings'])->pluck('message')->all())
        ->toBe(['The report still has 1 “[To complete …]” gap to fill in.']);
});
