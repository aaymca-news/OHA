<?php

use App\Actions\Oha\ApproveArtefact;
use App\Actions\Oha\SignAsBoard;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\ArtefactStatus;
use App\Models\BoardSignature;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Journey;

/*
 * Only the ODP is signed, by the movement's one user: its Board Chairperson. It
 * reaches them the moment an Administrator approves it; nobody "releases" it.
 */

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
});

it('validates the approved ODP when the Chairperson signs it, and says so', function () {
    $odp = $this->j->odp($this->j->assessmentAt('odp_approved'));
    $status = fn () => ArtefactStatus::query()->findOrFail($odp->id);

    expect($status()->validated)->toBeFalse();

    $signature = $this->j->sign($odp);

    expect($status()->validated)->toBeTrue()
        // Who signed, and what they typed as their signature: their initials.
        ->and($signature->signed_name)->toBe('Naledi Moyo')
        ->and($signature->isTyped())->toBeTrue()
        ->and($signature->signature_text)->toBe('N. M.')
        ->and($signature->signature_path)->toBeNull()
        // The exact version signed: the approved one, by its file's fingerprint.
        ->and($signature->document_id)->toBe($odp->approvedVersion()->firstOrFail()->id)
        ->and($signature->document_sha256)->toBe($odp->approvedVersion()->firstOrFail()->sha256);
});

it('lets only this movement’s Chairperson sign, only the ODP, and only once it is approved', function () {
    $assessment = $this->j->assessmentAt('odp_submitted');
    $odp = $this->j->odp($assessment);

    expect(fn () => $this->j->sign($odp))->toThrow(WorkflowRuleBroken::class, 'has not been approved yet')
        ->and(fn () => $this->j->sign($this->j->report($assessment)))->toThrow(WorkflowRuleBroken::class, 'Only the ODP is signed')
        ->and(fn () => $this->j->sign($this->j->form($assessment)))->toThrow(WorkflowRuleBroken::class, 'Only the ODP is signed');

    app(ApproveArtefact::class)->handle($odp, $this->j->admin);

    expect(fn () => $this->j->sign($odp, $this->j->ghanaChair))->toThrow(WorkflowRuleBroken::class, 'Only this movement')
        ->and(fn () => $this->j->sign($odp, $this->j->admin))->toThrow(WorkflowRuleBroken::class, 'Only this movement');

    $this->j->sign($odp);
    expect(fn () => $this->j->sign($odp))->toThrow(WorkflowRuleBroken::class, 'already been signed');
});

it('never shows a report or form as validated: they are approved, not signed', function () {
    $assessment = $this->j->assessmentAt('odp_signed');

    expect(ArtefactStatus::query()->findOrFail($this->j->report($assessment)->id)->validated)->toBeFalse()
        ->and(ArtefactStatus::query()->findOrFail($this->j->form($assessment)->id)->validated)->toBeFalse()
        ->and(ArtefactStatus::query()->findOrFail($this->j->odp($assessment)->id)->validated)->toBeTrue();
});

it('needs the confirmation and typed initials or a name', function () {
    $odp = $this->j->odp($this->j->assessmentAt('odp_approved'));
    $sign = fn (string $typed, bool $confirmed) => app(SignAsBoard::class)->handle($odp, $this->j->chair, $typed, $confirmed);

    expect(fn () => $sign('N. M.', false))->toThrow(WorkflowRuleBroken::class, 'Tick the box')
        ->and(fn () => $sign(' ', true))->toThrow(WorkflowRuleBroken::class, 'initials or your full name')
        ->and(fn () => $sign('N', true))->toThrow(WorkflowRuleBroken::class, 'at least two')
        ->and(fn () => $sign('12345', true))->toThrow(WorkflowRuleBroken::class, 'letters only')
        ->and(fn () => $sign('<b>NM</b>', true))->toThrow(WorkflowRuleBroken::class, 'letters only');

    // Initials as people type them, or the full name, with accents.
    expect($sign('  Naledi   Moyo-Bandá ', true)->signature_text)->toBe('Naledi Moyo-Bandá');
});

it('shows the typed signature to the Chairperson as they type, and on the signed ODP', function () {
    $assessment = $this->j->assessmentAt('odp_approved');
    $tab = route('assessments.show', ['assessment' => $assessment, 'tab' => 'odp']);

    $this->actingAs($this->j->chair)->get($tab)
        ->assertSee('Your signature')->assertSee('Type your initials or your full name, for example N. M. or Naledi Moyo.')
        ->assertDontSee('Draw with your mouse');

    $this->j->sign($this->j->odp($assessment));

    $this->actingAs($this->j->admin)->get($tab)
        ->assertSee('Validated by the board')->assertSee('signature-typed', escape: false)->assertSee('N. M.');
});

it('never lets a signature be changed afterwards', function () {
    $this->j->assessmentAt('odp_signed');

    expect(fn () => DB::transaction(fn () => DB::table('board_signatures')->update(['signed_name' => 'Someone else'])))
        ->toThrow(QueryException::class, 'cannot be changed');
    expect(BoardSignature::query()->value('signed_name'))->toBe('Naledi Moyo');
});

it('allows one active Board Chairperson per movement, in the database itself', function () {
    expect(fn () => DB::transaction(fn () => User::factory()->chair($this->j->zambia)->create()))
        ->toThrow(QueryException::class, 'users_one_chair_per_movement');

    // A deactivated former Chairperson does not count.
    $this->j->chair->update(['active' => false]);
    expect(User::factory()->chair($this->j->zambia)->create()->movement_id)->toBe($this->j->zambia->id);
});
