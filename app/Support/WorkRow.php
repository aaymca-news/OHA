<?php

namespace App\Support;

use App\Enums\ArtefactState;
use App\Models\Artefact;
use App\Models\Assessment;
use App\Models\Movement;
use App\Oha\FollowUpSchedule;
use Illuminate\Support\Carbon;

/**
 * One My Work entry, ready to show as a row (design standard of 10 Nov 2026): what it is
 * (icon), its title and link, one meta line, one status pill (and at most one more, for
 * what waits on the assessor), the target date, and its one action. Every kind of entry
 * from App\Queries\MyWork comes out the same shape, so every row looks the same.
 */
final class WorkRow
{
    /**
     * @param  array<string, mixed>  $entry  one item of App\Queries\MyWork::for()
     * @return array{icon: string, title: string, url: string, meta: string, pill: array{0: string, 1: string}, extra: array{0: string, 1: string}|null, blocked: string|null, days: int|null, actionable: bool, button: string, primary: bool}
     */
    public static function from(array $entry, ?string $group = null): array
    {
        $row = match (true) {
            isset($entry['opened']) => self::opened($entry),
            isset($entry['started']) => self::started($entry),
            isset($entry['done']) => self::done($entry),
            isset($entry['movement']) => self::toStart($entry),
            default => self::document($entry, $group),
        };

        return $row + [
            'extra' => null,
            'blocked' => $entry['blocked'] ?? null,
            'days' => $entry['days_left'] ?? null,
            'actionable' => (bool) $entry['actionable'],
        ];
    }

    /**
     * An assessment opened with nothing uploaded yet: the OHA form comes first.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private static function opened(array $entry): array
    {
        /** @var Assessment $assessment */
        $assessment = $entry['artefact']->assessment;

        return [
            'icon' => 'upload_file',
            'title' => $entry['movement']->name.' · '.$assessment->period_label,
            'url' => route('assessments.show', ['assessment' => $assessment->id, 'tab' => 'form']),
            'meta' => collect(['Assigned to you', $assessment->opened_at ? 'opened '.$assessment->opened_at->format(DateFormats::DAY) : null, 'the OHA form is not uploaded yet'])->filter()->join(' · '),
            'pill' => ['neutral', 'Not started'],
            'button' => 'Upload the OHA form',
            'primary' => true,
        ];
    }

    /**
     * An assessment under way, with where it stands; the assessor's to act on when it is with them.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private static function started(array $entry): array
    {
        /** @var Artefact $artefact */
        $artefact = $entry['artefact'];
        $assessment = $artefact->assessment;
        $state = ArtefactState::display($artefact->state->value);
        // The ODP approved: closing the assessment (Stage 1 done) is the next step.
        if ($entry['ready'] ?? false) {
            return self::readyToClose($entry) + ['icon' => 'play_circle', 'title' => $entry['movement']->name.' · '.$assessment->period_label];
        }

        return [
            'icon' => 'play_circle',
            'title' => $entry['movement']->name.' · '.$assessment->period_label,
            'url' => route('assessments.show', ['assessment' => $assessment->id, 'tab' => $artefact->kind->value]),
            'meta' => collect([
                $assessment->opened_at ? 'Started '.$assessment->opened_at->format(DateFormats::DAY) : null,
                'now: '.$artefact->kind->label().', '.mb_strtolower($state['label']),
                $entry['holder'],
            ])->filter()->join(' · '),
            'pill' => $entry['actionable'] ? ['warning', 'Waiting on you'] : ['info', 'Assessment started'],
            'button' => 'Open',
            'primary' => (bool) $entry['actionable'],
        ];
    }

    /**
     * An assessment whose ODP is approved, waiting to be closed (which sets Baseline 1).
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private static function readyToClose(array $entry): array
    {
        /** @var Artefact $odp */
        $odp = $entry['artefact'];

        return [
            'url' => route('assessments.show', ['assessment' => $odp->assessment_id, 'tab' => 'odp']).'#close',
            'meta' => collect(['ODP approved'.($odp->approved_at ? ' '.$odp->approved_at->format(DateFormats::DAY) : ''), 'closing it sets Baseline 1 and starts the follow-up'])->join(' · '),
            'pill' => ['warning', 'Ready to close'],
            'button' => $entry['actionable'] ? 'Close the assessment' : 'Open',
            'primary' => (bool) $entry['actionable'],
        ];
    }

    /**
     * Stage 1 done (closed): its follow-ups under way; the one under way to run when it is open or past its date.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private static function done(array $entry): array
    {
        /** @var Movement $movement */
        $movement = $entry['movement'];
        /** @var Assessment $done */
        $done = $entry['done'];
        /** @var array{number: int, due_on: Carbon|null, state: string, overdue: bool}|null $followUp */
        $followUp = $entry['follow_up'] ?? null;
        $closed = $done->isFollowUpClosed();

        return [
            'icon' => 'verified',
            'title' => $movement->name.' · '.$done->period_label,
            'url' => route('follow-up.show', $done),
            'meta' => collect([
                'Stage 1 done',
                match (true) {
                    $closed => 'ready for the next assessment',
                    $followUp === null => 'the three follow-ups are closed',
                    $followUp['due_on'] !== null => 'follow-up '.$followUp['number'].' planned for '.$followUp['due_on']->format(DateFormats::DAY),
                    default => 'follow-up '.$followUp['number'].'’s date not set yet',
                },
            ])->join(' · '),
            'pill' => $closed ? ['good', 'Cycle complete'] : ['warning', 'Follow-ups ongoing'],
            'extra' => match (true) {
                $followUp === null => null,
                $followUp['overdue'] => ['critical', 'Follow-up '.$followUp['number'].' overdue'],
                $followUp['state'] === FollowUpSchedule::OPEN => ['info', 'Follow-up '.$followUp['number'].' open'],
                default => null,
            },
            'button' => $entry['actionable'] ? 'Open the follow-up' : 'Open',
            'primary' => (bool) $entry['actionable'],
        ];
    }

    /**
     * A movement assigned to this person with no assessment under way: theirs to open.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private static function toStart(array $entry): array
    {
        /** @var Movement $movement */
        $movement = $entry['movement'];
        $last = $movement->status?->last_assessed_on;

        return [
            'icon' => 'flag',
            'title' => $movement->name,
            'url' => route('movements.show', $movement),
            'meta' => 'Assigned to you · '.($last ? 'last assessed '.$last->format(DateFormats::MONTH).' · its cycle is closed' : 'not assessed yet'),
            // Assessed before, its cycle closed: the next assessment can start (no band-based date).
            'pill' => $last ? ['info', 'Ready for the next assessment'] : ['neutral', 'Not started'],
            'button' => $entry['actionable'] ? 'Start the assessment' : 'Open',
            'primary' => (bool) $entry['actionable'],
        ];
    }

    /**
     * A form, report or ODP: to approve, to close, to sign (optional), or with information missing.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private static function document(array $entry, ?string $group): array
    {
        /** @var Artefact $artefact */
        $artefact = $entry['artefact'];
        $assessment = $artefact->assessment;
        $state = ArtefactState::display($artefact->state->value);
        $gaps = $group === 'gaps';
        if ($group === 'close') {
            return self::readyToClose($entry) + ['icon' => 'lock', 'title' => $assessment->movement->name.' · '.$assessment->period_label];
        }
        if ($group === 'sign') {
            return [
                'icon' => 'draw',
                'title' => $assessment->movement->name.' · '.$assessment->period_label.' ODP',
                'url' => route('assessments.show', ['assessment' => $assessment->id, 'tab' => 'odp']),
                'meta' => 'Approved by AAYMCA · you may sign it on behalf of your board',
                'pill' => ['neutral', 'Optional'],
                'days' => null,
                'button' => $entry['actionable'] ? 'Sign' : 'Open',
                'primary' => false,
            ];
        }

        return [
            'icon' => $gaps ? 'rule' : ['form' => 'table_view', 'report' => 'description', 'odp' => 'checklist'][$artefact->kind->value],
            'title' => $assessment->movement->name.' · '.$assessment->period_label,
            // The gaps are in the OHA form, whatever step the assessment is now at.
            'url' => route('assessments.show', ['assessment' => $assessment->id, 'tab' => $gaps ? 'form' : $artefact->kind->value]),
            'meta' => $gaps
                ? collect(['The OHA form went forward with gaps', 'now: '.$artefact->kind->label().', '.mb_strtolower($state['label']), $entry['holder']])->join(' · ')
                : collect([$artefact->kind->label(), $entry['holder'], $artefact->submitter ? 'submitted by '.$artefact->submitter->name : null])->filter()->join(' · '),
            'pill' => $gaps ? ['warning', 'Information missing'] : [$state['tone'], $state['label']],
            // A gap has no target date of its own (the step's date is someone else's).
            'days' => $gaps ? null : ($entry['days_left'] ?? null),
            'button' => $entry['actionable'] && $group === 'approve' ? 'Approve' : 'Open',
            // Missing information is to follow up, not a step waiting on them (it is not on the badge).
            'primary' => $entry['actionable'] && $group !== 'gaps',
        ];
    }
}
