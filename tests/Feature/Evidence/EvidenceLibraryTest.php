<?php

use App\Actions\Evidence\UploadEvidence;
use App\Actions\Oha\OpenAssessment;
use App\Actions\Users\InviteUser;
use App\Enums\Role;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Category;
use App\Models\Evidence;
use App\Models\User;
use App\Notifications\WorkflowNotice;
use App\Oha\Answer;
use App\Oha\EvidenceQuestions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Journey;

/*
 * The National General Secretary and the evidence library: supporting documents for an
 * assessment, linked to the OHA form's questions and the ODP's tasks, shared with other
 * movements when the movement chooses.
 */

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
    $this->ngs = User::factory()->generalSecretary($this->j->zambia)->create(['name' => 'Mwila Banda']);
    $this->ghanaNgs = User::factory()->generalSecretary($this->j->ghana)->create(['name' => 'Kofi Mensah']);
    $this->assessment = $this->j->assessmentAt('odp_approved');
    // An evidence question Zambia's form answers "Yes" that a document proves, and its category.
    $answers = $this->j->form($this->assessment)->currentUpload()->firstOrFail()->answers;
    $this->yes = collect(array_keys(EvidenceQuestions::LABELS))
        ->first(fn ($code) => Answer::isYes($answers[$code] ?? null) && EvidenceQuestions::accepts($code) === EvidenceQuestions::DOCUMENT);
    $this->category = Category::query()->where('code', EvidenceQuestions::categoryOf($this->yes))->firstOrFail();
    $this->comms = Category::query()->where('code', 'comms')->firstOrFail();
    $this->add = fn (User $by, array $fields = [], string $name = 'Policy.pdf', ?string $content = null) => $this->actingAs($by)->post(route('evidence.store', $this->j->zambia), [
        'assessment_id' => $this->assessment->id,
        'kind' => 'file',
        'file' => UploadedFile::fake()->createWithContent($name, $content ?? '%PDF-1.4 '.$name.' '.uniqid()),
        ...$fields,
    ]);
    $this->addLink = fn (User $by, string $url, array $fields = []) => $this->actingAs($by)->post(route('evidence.store', $this->j->zambia), [
        'assessment_id' => $this->assessment->id,
        'kind' => 'link',
        'url' => $url,
        ...$fields,
    ]);
});

it('takes the evidence each question needs: a link for the website, a document for a policy', function () {
    // The website is proven by its address, not by a file.
    ($this->add)($this->ngs, ['category' => 'comms', 'questions' => ['Q811']], 'Website.pdf')
        ->assertSessionHasErrors(['action' => 'Q811 Website is proven by a link, not a file: A link to the YMCA’s website.']);
    ($this->addLink)($this->ngs, 'https://zambiaymca.org', ['category' => 'comms', 'questions' => ['Q811']])->assertSessionHasNoErrors();

    // A strategy document is proven by the document, not by a link.
    ($this->addLink)($this->ngs, 'https://zambiaymca.org/strategy', ['category' => 'comms', 'questions' => ['Q812']])
        ->assertSessionHasErrors(['action' => 'Q812 Communications strategy is proven by a document, not a link: The communications strategy.']);
    // The annual report takes either.
    ($this->addLink)($this->ngs, 'https://zambiaymca.org/annual-report', ['category' => 'comms', 'questions' => ['Q810']])->assertSessionHasNoErrors();
    ($this->addLink)($this->ngs, 'not a link')->assertSessionHasErrors('action');
    ($this->addLink)($this->ngs, 'https://zambiaymca.org')->assertSessionHasErrors(['action' => 'This link is already in the library, as “zambiaymca.org”.']);

    $website = Evidence::query()->where('url', 'https://zambiaymca.org')->firstOrFail();
    expect($website->isLink())->toBeTrue()
        ->and($website->title)->toBe('zambiaymca.org')
        ->and($website->path)->toBeNull();
    // Opening it goes to the website, through the access check.
    $this->actingAs($this->j->chair)->get(route('evidence.download', $website))->assertRedirect('https://zambiaymca.org');
    $this->actingAs($this->ghanaNgs)->get(route('evidence.download', $website))->assertForbidden();
});

it('flags what is missing inside each category’s folder, with exactly what is needed', function () {
    $this->actingAs($this->ngs)->get(route('evidence.index', $this->j->zambia))->assertOk()
        ->assertSee('Needed for the form’s “Yes” answers')
        ->assertSee('Needed: '.EvidenceQuestions::needed($this->yes), escape: false)
        ->assertSee('missing')
        ->assertSee(route('evidence.index', ['movement' => $this->j->zambia, 'assessment' => $this->assessment->id, 'for' => $this->yes]).'#add');

    // "Add" opens the form ready for that question.
    $this->actingAs($this->ngs)->get(route('evidence.index', ['movement' => $this->j->zambia, 'for' => 'Q811']))->assertOk()
        ->assertSee('For <strong>Q811 Website</strong>', escape: false)->assertSee('A link to the YMCA’s website.');
});

function evidenceFile(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'ev');
    file_put_contents($path, $content);

    return $path;
}

it('shows the National General Secretary their own movement only, with nothing to sign or approve', function () {
    $this->actingAs($this->ngs)->get(route('movements.show', $this->j->zambia))->assertOk()->assertSee('Mwila Banda');
    $this->actingAs($this->ngs)->get(route('assessments.show', $this->assessment))->assertOk();
    $this->actingAs($this->ngs)->get(route('resources'))->assertOk()->assertSee('Zambia YMCA')->assertDontSee('Ghana YMCA');
    $this->actingAs($this->ngs)->get(route('movements.show', $this->j->ghana))->assertForbidden();
    $this->actingAs($this->ngs)->get(route('movements.index'))->assertForbidden();

    expect($this->ngs->isSecretariat())->toBeFalse()
        ->and($this->ngs->can('sign', $this->j->odp($this->assessment)))->toBeFalse()
        ->and($this->ngs->can('approve', $this->j->form($this->assessment)))->toBeFalse();
    // No work in the platform yet: no My Work.
    $this->actingAs($this->ngs)->get(route('my-work'))->assertRedirect(route('dashboard'));
});

it('adds evidence linked to a form question and an ODP task, and tells the assessors by app and email', function () {
    ($this->add)($this->ngs, [
        'title' => 'Our evidence',
        'category' => $this->category->code,
        'questions' => [$this->yes],
        'tasks' => $this->category->form_order.'.2',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $evidence = Evidence::query()->firstOrFail();
    expect($evidence->title)->toBe('Our evidence')
        ->and($evidence->category_id)->toBe($this->category->id)
        ->and($evidence->isShared())->toBeFalse()
        ->and(DB::table('evidence_questions')->where('evidence_id', $evidence->id)->pluck('question_code')->all())->toBe([$this->yes])
        ->and(DB::table('evidence_odp_tasks')->where('evidence_id', $evidence->id)->pluck('task')->all())->toBe([$this->category->form_order.'.2']);
    Storage::disk('oha')->assertExists($evidence->path);
    expect(DB::table('audit_events')->where('action', 'evidence.added')->where('actor_id', $this->ngs->id)->exists())->toBeTrue();

    Notification::assertSentTo($this->j->assessor, WorkflowNotice::class, function (WorkflowNotice $n, array $channels) {
        return str_contains($n->body, 'Mwila Banda') && str_contains($n->body, 'Our evidence') && $channels === ['database', 'mail'];
    });
    // Not told of their own addition (they are told of approvals, which is another matter).
    Notification::assertNotSentTo($this->ngs, WorkflowNotice::class, fn (WorkflowNotice $n) => str_contains($n->body, 'Our evidence'));
});

it('shows the link on the Form tab, the ODP tab and in the library', function () {
    ($this->add)($this->ngs, ['title' => 'Our evidence', 'category' => $this->category->code, 'questions' => [$this->yes], 'tasks' => $this->category->form_order.'.1']);

    $this->actingAs($this->j->assessor)->get(route('assessments.show', ['assessment' => $this->assessment, 'tab' => 'form']))
        ->assertOk()->assertSee('Evidence for the form’s answers')->assertSee(EvidenceQuestions::LABELS[$this->yes])->assertSee('Our evidence')
        ->assertSee('Supplied')->assertSee('Missing');
    $this->actingAs($this->j->assessor)->get(route('assessments.show', ['assessment' => $this->assessment, 'tab' => 'odp']))
        ->assertOk()->assertSee('Evidence for the ODP tasks')->assertSee('Task '.$this->category->form_order.'.1')->assertSee('Our evidence');
    $this->actingAs($this->j->chair)->get(route('evidence.index', $this->j->zambia))
        ->assertOk()->assertSee('Our evidence')->assertSee('ODP '.$this->category->form_order.'.1')->assertSee('“Yes” answers with evidence', escape: false);
});

it('refuses what the library does not take', function () {
    ($this->add)($this->ngs, [], 'setup.exe')->assertSessionHasErrors(['action' => 'This kind of file cannot be added. Add a document, spreadsheet, presentation, image or text file (PDF, Word, Excel, PowerPoint, JPG, PNG…).']);

    $other = Category::query()->where('code', '!=', $this->category->code)->orderBy('form_order')->firstOrFail();
    ($this->add)($this->ngs, ['category' => $other->code, 'questions' => [$this->yes]])->assertSessionHasErrors('action');
    ($this->add)($this->ngs, ['category' => $this->category->code, 'tasks' => ($this->category->form_order % 9 + 1).'.1'])->assertSessionHasErrors('action');
    ($this->add)($this->ngs, ['tasks' => '12'])->assertSessionHasErrors('action');

    ($this->add)($this->ngs, [], 'Same.pdf', 'same bytes')->assertSessionHasNoErrors();
    ($this->add)($this->ngs, [], 'Copy.pdf', 'same bytes')->assertSessionHasErrors(['action' => 'This file is already in the library, as “Same”.']);

    expect(Evidence::query()->count())->toBe(1);
});

it('lets AAYMCA staff and the National General Secretary add evidence, but not the Board Chairperson or another movement', function () {
    ($this->add)($this->j->otherStaff)->assertSessionHasNoErrors();
    ($this->add)($this->j->chair)->assertSessionHasErrors(['action' => 'Evidence is added by the movement’s National General Secretary and by AAYMCA staff.']);
    ($this->add)($this->ghanaNgs)->assertSessionHasErrors('action');
    $this->actingAs($this->ghanaNgs)->get(route('evidence.index', $this->j->zambia))->assertForbidden();

    // Staff adding evidence tell nobody; only the National General Secretary's additions are announced.
    Notification::assertNotSentTo($this->j->assessor, WorkflowNotice::class, fn (WorkflowNotice $n) => str_starts_with($n->subject, 'New evidence'));
});

it('shares a document with other movements without opening up the movement', function () {
    $evidence = app(UploadEvidence::class)->handle($this->ngs, $this->assessment, evidenceFile('safeguarding'), 'Safeguarding Policy.pdf');

    // Unshared: another movement cannot have it.
    $this->actingAs($this->ghanaNgs)->get(route('evidence.download', $evidence))->assertForbidden();
    $this->actingAs($this->ghanaNgs)->get(route('resources'))->assertDontSee('Safeguarding Policy');

    // Staff who are not Administrators do not decide sharing.
    $this->actingAs($this->j->otherStaff)->put(route('evidence.share', $evidence), ['shared' => 1])->assertSessionHasErrors('action');
    $this->actingAs($this->ngs)->put(route('evidence.share', $evidence), ['shared' => 1])->assertSessionHasNoErrors();
    expect($evidence->refresh()->isShared())->toBeTrue()
        ->and($evidence->shared_by)->toBe($this->ngs->id);

    $this->actingAs($this->ghanaNgs)->get(route('resources'))->assertSee('Shared by other movements')->assertSee('Safeguarding Policy')->assertSee('Zambia YMCA');
    $this->actingAs($this->ghanaNgs)->get(route('evidence.download', $evidence))->assertOk()->assertDownload('Safeguarding Policy.pdf');
    // Only the document: not Zambia's library, page, scores or assessments.
    $this->actingAs($this->ghanaNgs)->get(route('evidence.index', $this->j->zambia))->assertForbidden();
    $this->actingAs($this->ghanaNgs)->get(route('movements.show', $this->j->zambia))->assertForbidden();
    $this->actingAs($this->ghanaNgs)->get(route('assessments.show', $this->assessment))->assertForbidden();

    // An Administrator may stop sharing it.
    $this->actingAs($this->j->admin)->put(route('evidence.share', $evidence), ['shared' => 0])->assertSessionHasNoErrors();
    $this->actingAs($this->ghanaNgs)->get(route('evidence.download', $evidence))->assertForbidden();
});

it('lets the National General Secretary remove what they added, and AAYMCA staff anything', function () {
    $theirs = app(UploadEvidence::class)->handle($this->ngs, $this->assessment, evidenceFile('theirs'), 'Theirs.pdf');
    $staffs = app(UploadEvidence::class)->handle($this->j->assessor, $this->assessment, evidenceFile('staff'), 'Staff.pdf');

    $this->actingAs($this->ngs)->delete(route('evidence.destroy', $staffs))->assertSessionHasErrors('action');
    $this->actingAs($this->ngs)->delete(route('evidence.destroy', $theirs))->assertSessionHasNoErrors();
    $this->actingAs($this->j->otherStaff)->delete(route('evidence.destroy', $staffs))->assertSessionHasNoErrors();

    expect(Evidence::query()->count())->toBe(0)
        ->and(DB::table('audit_events')->where('action', 'evidence.removed')->count())->toBe(2);
    Storage::disk('oha')->assertMissing($theirs->path);
});

it('keeps evidence with its assessment: a new assessment starts with an empty library', function () {
    app(UploadEvidence::class)->handle($this->ngs, $this->assessment, evidenceFile('old'), 'Strategic Plan 2024.pdf');
    $this->travelTo(now()->addYear());
    $next = app(OpenAssessment::class)->handle($this->j->assessor, $this->j->zambia, 'Feb 2027', now()->startOfMonth());

    $this->actingAs($this->ngs)->get(route('evidence.index', ['movement' => $this->j->zambia, 'assessment' => $next->id]))
        ->assertOk()->assertDontSee('Strategic Plan 2024');
    $this->actingAs($this->ngs)->get(route('evidence.index', ['movement' => $this->j->zambia, 'assessment' => $this->assessment->id]))
        ->assertOk()->assertSee('Strategic Plan 2024');
});

it('invites a National General Secretary for a movement, one per movement', function () {
    $invite = fn (string $email, bool $replace = false) => app(InviteUser::class)->handle($this->j->admin, 'New NGS', $email, Role::Ngs, chairOf: $this->j->zambia, confirmReplace: $replace);

    expect(fn () => $invite('second@zambiaymca.org'))->toThrow(WorkflowRuleBroken::class, 'Mwila Banda is Zambia YMCA’s National General Secretary, and a movement has one.');

    $new = $invite('second@zambiaymca.org', replace: true);
    expect($new->role)->toBe(Role::Ngs)
        ->and($new->movement_id)->toBe($this->j->zambia->id)
        ->and($this->ngs->refresh()->active)->toBeFalse()
        // The Board Chairperson is a separate role and stays.
        ->and($this->j->chair->refresh()->active)->toBeTrue();
});

it('moves evidence filed in the wrong folder, taking off links that belong elsewhere', function () {
    ($this->add)($this->ngs, ['title' => 'Misfiled', 'category' => $this->category->code, 'questions' => [$this->yes], 'tasks' => $this->category->form_order.'.1']);
    $evidence = Evidence::query()->firstOrFail();
    $other = Category::query()->where('code', '!=', $this->category->code)->orderBy('form_order')->firstOrFail();

    // To General: every link stays.
    $this->actingAs($this->ngs)->put(route('evidence.move', $evidence), ['category' => 'general'])
        ->assertSessionHasNoErrors()->assertSessionHas('status', '“Misfiled” moved to General.');
    expect($evidence->refresh()->category_id)->toBeNull()
        ->and(DB::table('evidence_questions')->where('evidence_id', $evidence->id)->count())->toBe(1);

    // To another category: its links to this category's question and task come off.
    $this->actingAs($this->ngs)->put(route('evidence.move', $evidence), ['category' => $other->code])
        ->assertSessionHas('status', fn (string $s) => str_contains($s, "moved to {$other->name}") && str_contains($s, EvidenceQuestions::shown($this->yes)) && str_contains($s, 'ODP '.$this->category->form_order.'.1'));
    expect($evidence->refresh()->category_id)->toBe($other->id)
        ->and(DB::table('evidence_questions')->where('evidence_id', $evidence->id)->count())->toBe(0)
        ->and(DB::table('evidence_odp_tasks')->where('evidence_id', $evidence->id)->count())->toBe(0)
        ->and(DB::table('audit_events')->where('action', 'evidence.moved')->count())->toBe(2);

    // A folder has to be chosen; nothing moves on an empty choice.
    $this->actingAs($this->ngs)->put(route('evidence.move', $evidence), ['category' => ''])->assertSessionHasErrors('category');
    expect($evidence->refresh()->category_id)->toBe($other->id);
});

it('lets the National General Secretary move only what they added, and AAYMCA staff anything', function () {
    $staffs = app(UploadEvidence::class)->handle($this->j->assessor, $this->assessment, evidenceFile('staff file'), 'Staff.pdf');

    $this->actingAs($this->ngs)->put(route('evidence.move', $staffs), ['category' => 'comms'])
        ->assertSessionHasErrors(['action' => 'You can move only the evidence you added. AAYMCA staff can move any.']);
    $this->actingAs($this->j->otherStaff)->put(route('evidence.move', $staffs), ['category' => 'comms'])->assertSessionHasNoErrors();
    $this->actingAs($this->j->chair)->put(route('evidence.move', $staffs), ['category' => 'general'])->assertSessionHasErrors('action');

    expect($staffs->refresh()->category_id)->toBe($this->comms->id);
});
