<?php

namespace App\Actions\Oha;

use App\Actions\Oha\Concerns\EnforcesPolicy;
use App\Actions\Oha\Concerns\LocksArtefact;
use App\Enums\ArtefactKind;
use App\Enums\ArtefactState;
use App\Enums\FindingSeverity;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Artefact;
use App\Models\User;
use App\Notifications\WorkflowNotice;
use App\Oha\Report\ReadReport;
use App\Oha\Report\ReportChecker;
use App\Support\Approvers;
use App\Support\Audit;
use App\Support\Notify;
use Illuminate\Support\Facades\DB;

/**
 * Sends a form, report or ODP to the Administrators for approval. Every
 * Administrator except the submitter is told; the first to decide settles it.
 *
 * An OHA form with open gaps may go forward, but never silently: it takes an
 * explicit acknowledgement, which is recorded and shown to the approver.
 */
final class SubmitForApproval
{
    use EnforcesPolicy, LocksArtefact;

    public function __construct(private readonly ReportChecker $reportChecker) {}

    public function handle(Artefact $artefact, User $submitter, bool $acknowledgeGaps = false, ?string $reason = null): Artefact
    {
        return DB::transaction(function () use ($artefact, $submitter, $acknowledgeGaps, $reason): Artefact {
            $artefact = $this->lock($artefact);
            $this->ensure($submitter, 'submit', $artefact);

            $assessment = $artefact->assessment;
            $openGaps = $artefact->kind === ArtefactKind::Form ? $this->openGaps($artefact) : 0;

            if ($openGaps > 0 && ! $acknowledgeGaps) {
                throw new WorkflowRuleBroken($openGaps.' item'.($openGaps === 1 ? ' is' : 's are').' missing from the form. Confirm you want to proceed without '.($openGaps === 1 ? 'it' : 'them').'.');
            }

            $versioned = $artefact->kind !== ArtefactKind::Form;
            $version = $versioned ? $artefact->latestVersion()->first() : null;
            if ($versioned && $version === null) {
                throw new WorkflowRuleBroken('Upload the '.($artefact->kind === ArtefactKind::Odp ? 'ODP' : 'report').' first.');
            }

            // The report check never stops a submission; the approvers are simply told what it found.
            $flagged = $artefact->kind === ArtefactKind::Report && $version->extracted !== null
                ? count($this->reportChecker->check(ReadReport::fromArray($version->extracted), $assessment)['findings'])
                : 0;

            $approvers = Approvers::for($submitter);
            $from = $artefact->state;

            $artefact->update([
                'state' => ArtefactState::PendingApproval,
                'submitted_by' => $submitter->id,
                'submitted_at' => now(),
                'approved_by' => null,
                'approved_at' => null,
                'gap_ack' => $openGaps > 0,
                'gap_ack_reason' => $openGaps > 0 && $reason !== null && trim($reason) !== '' ? trim($reason) : null,
            ]);

            Audit::record($submitter, $artefact->kind->value.'.submitted', $artefact, $assessment, $from, $artefact->state, payload: array_filter([
                'open_gaps' => $openGaps,
                'gap_ack_reason' => $artefact->gap_ack_reason,
                'version' => $version?->versionNumber(),
                'approvers' => $approvers->pluck('id')->all(),
            ], fn ($v) => $v !== null));

            $name = self::label($artefact).($version !== null ? ' (version '.$version->versionNumber().')' : '');
            $movement = $assessment->movement->name;
            Notify::send($approvers, new WorkflowNotice(
                "Approval needed: {$name}, {$movement}",
                "{$submitter->name} submitted the ".self::inSentence($name)." for {$movement} ({$assessment->period_label}). Any Administrator may approve it or send it back."
                    .($openGaps > 0 ? " It was submitted with {$openGaps} missing item".($openGaps === 1 ? '' : 's').': check them before approving.' : '')
                    .($flagged > 0 ? " The report check flagged {$flagged} item".($flagged === 1 ? '' : 's').' to look at before approving.' : ''),
                Notify::link($assessment), 'action',
            ));

            // The movement's other assessors own the follow-up with the movement.
            if ($openGaps > 0) {
                Notify::send(Notify::assessorsOf($assessment), new WorkflowNotice(
                    "Missing information: {$movement}",
                    "{$submitter->name} submitted the OHA form for {$movement} with {$openGaps} missing item".($openGaps === 1 ? '' : 's').'. They stay flagged until resolved.',
                    Notify::link($assessment), 'serious',
                ), except: $submitter);
            }

            return $artefact;
        });
    }

    /**
     * A label inside a sentence: "the health assessment report", but "the OHA form" and
     * "the ODP", whose capitals are part of the name.
     */
    public static function inSentence(string $label): string
    {
        return preg_match('/^\p{Lu}\p{Lu}/u', $label) ? $label : lcfirst($label);
    }

    public static function label(Artefact $artefact): string
    {
        return match ($artefact->kind) {
            ArtefactKind::Form => 'OHA form',
            ArtefactKind::Report => 'Health assessment report',
            ArtefactKind::Odp => 'ODP',
        };
    }

    private function openGaps(Artefact $form): int
    {
        return $form->currentUpload()->first()?->findings()
            ->where('severity', FindingSeverity::Missing)
            ->whereNull('resolved_at')
            ->count() ?? 0;
    }
}
