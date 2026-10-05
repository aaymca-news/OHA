<?php

use App\Enums\ArtefactKind;
use App\Enums\ArtefactState;
use App\Enums\DocumentPurpose;
use App\Enums\HolderRole;
use App\Models\Artefact;
use App\Models\ArtefactStatus;
use App\Models\Assessment;
use App\Models\BoardSignature;
use App\Models\Document;
use App\Models\TimelineItem;
use App\Models\User;
use App\Models\WorkItem;

/**
 * An assessment opened on the given date, with its three artefacts in the given states.
 *
 * @param  array<string, array<string, mixed>>  $artefacts  keyed by kind
 */
function assessmentOpened(string $openedAt, array $artefacts = []): Assessment
{
    $assessment = Assessment::factory()->create(['opened_at' => $openedAt]);

    foreach (ArtefactKind::cases() as $kind) {
        Artefact::factory()->for($assessment)->create(['kind' => $kind] + ($artefacts[$kind->value] ?? []));
    }

    return $assessment;
}

function effectiveState(Assessment $assessment, ArtefactKind $kind): string
{
    return ArtefactStatus::query()
        ->where('assessment_id', $assessment->id)
        ->where('kind', $kind->value)
        ->value('effective_state');
}

it('locks the report until a form is uploaded, and the ODP until a report version is saved', function () {
    $fresh = assessmentOpened('2026-09-01');
    $formIn = assessmentOpened('2026-09-01', ['form' => ['state' => ArtefactState::PendingApproval]]);
    $reportSaved = assessmentOpened('2026-09-01', [
        'form' => ['state' => ArtefactState::PendingApproval],
        'report' => ['state' => ArtefactState::Drafted],
    ]);
    Document::factory()->create([
        'artefact_id' => $reportSaved->artefacts()->where('kind', 'report')->value('id'),
        'purpose' => DocumentPurpose::Uploaded,
    ]);

    expect(effectiveState($fresh, ArtefactKind::Form))->toBe('not_started')
        ->and(effectiveState($fresh, ArtefactKind::Report))->toBe('locked')
        ->and(effectiveState($fresh, ArtefactKind::Odp))->toBe('locked')
        ->and(effectiveState($formIn, ArtefactKind::Report))->toBe('not_started')
        ->and(effectiveState($formIn, ArtefactKind::Odp))->toBe('locked')
        ->and(effectiveState($reportSaved, ArtefactKind::Odp))->toBe('not_started');
});

it('puts a new assessment with the assessor, due by the form gate deadline', function () {
    $assessment = assessmentOpened('2026-09-01 09:00:00');
    $item = WorkItem::query()->findOrFail($assessment->id);

    // Gate: opened + 21 days. Assessor turnaround: opened + 14 days. The sooner wins.
    expect($item->holder_role)->toBe(HolderRole::Assessor)
        ->and($item->artefact_kind)->toBe(ArtefactKind::Form)
        ->and($item->gate_due_on->toDateString())->toBe('2026-09-22')
        ->and($item->turnaround_due_on->toDateString())->toBe('2026-09-15')
        ->and($item->due_on->toDateString())->toBe('2026-09-15');
});

it('gives the Administrators five days from submission', function () {
    $assessment = assessmentOpened('2026-09-01', ['form' => [
        'state' => ArtefactState::PendingApproval,
        'submitted_at' => '2026-09-10 12:00:00',
    ]]);

    $item = WorkItem::query()->findOrFail($assessment->id);

    expect($item->holder_role)->toBe(HolderRole::Approver)
        ->and($item->due_on->toDateString())->toBe('2026-09-15');
});

it('lets a deadline a person set override both clocks', function () {
    $assessment = assessmentOpened('2026-09-01');
    TimelineItem::factory()->for($assessment)->gateDeadline('form', '2026-10-30')->create();

    $item = WorkItem::query()->findOrFail($assessment->id);

    expect($item->gate_due_set_by_hand)->toBeTrue()
        ->and($item->due_on->toDateString())->toBe('2026-10-30');
});

it('sends an approved ODP straight to the Board Chairperson for signature', function () {
    $toSign = assessmentOpened('2026-06-01', [
        'form' => ['state' => ArtefactState::Approved],
        'report' => ['state' => ArtefactState::Approved],
        'odp' => ['state' => ArtefactState::Approved, 'approved_at' => '2026-08-01'],
    ]);
    $item = WorkItem::query()->findOrFail($toSign->id);

    // Board turnaround: 21 days from approval; board gate: opened + 98 days. The sooner wins.
    expect($item->holder_role)->toBe(HolderRole::Board)
        ->and($item->gate_code)->toBe('board')
        ->and($item->due_on->toDateString())->toBe('2026-08-22');
});

it('closes the work item once the board has signed the ODP', function () {
    $assessment = assessmentOpened('2026-06-01', [
        'form' => ['state' => ArtefactState::Approved],
        'report' => ['state' => ArtefactState::Approved],
        'odp' => ['state' => ArtefactState::Approved, 'approved_at' => '2026-08-01'],
    ]);
    BoardSignature::query()->create([
        'artefact_id' => $assessment->odp()->value('id'), 'signed_by' => User::factory()->chair($assessment->movement)->create()->id,
        'signed_name' => 'Chair', 'signature_disk' => 'oha', 'signature_path' => 's.png',
        'signature_sha256' => str_repeat('a', 64), 'document_sha256' => str_repeat('b', 64), 'signed_at' => now(),
    ]);

    expect(WorkItem::query()->find($assessment->id))->toBeNull();
});

it('flags overdue work', function () {
    $assessment = assessmentOpened(now()->subDays(30)->toDateTimeString());
    $item = WorkItem::query()->findOrFail($assessment->id);

    expect($item->overdue)->toBeTrue()
        ->and($item->days_left)->toBe(-16);
});
