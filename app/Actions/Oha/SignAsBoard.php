<?php

namespace App\Actions\Oha;

use App\Actions\Oha\Concerns\EnforcesPolicy;
use App\Actions\Oha\Concerns\LocksArtefact;
use App\Exceptions\WorkflowRuleBroken;
use App\Models\Artefact;
use App\Models\BoardSignature;
use App\Models\User;
use App\Notifications\WorkflowNotice;
use App\Support\Audit;
use App\Support\Notify;
use Illuminate\Support\Facades\DB;

/**
 * The Board Chairperson signs the approved ODP online, which validates it.
 *
 * They sign by typing their initials or full name. Kept as evidence: what they typed,
 * who they are, when, from where, and the exact version signed: the last approved
 * one, with its file's SHA-256 fingerprint. From then on the ODP is frozen, and with
 * it the OHA form and the report it rests on; its implementation is followed in Stage 2.
 * Signing never holds the Secretariat back: it only changes the document's label.
 */
final class SignAsBoard
{
    use EnforcesPolicy, LocksArtefact;

    public function handle(Artefact $artefact, User $chair, string $typedSignature, bool $confirmed, ?string $comment = null, ?string $ip = null, ?string $userAgent = null): BoardSignature
    {
        if (! $confirmed) {
            throw new WorkflowRuleBroken('Tick the box to confirm you have read the document and validate it on behalf of the board.');
        }
        $mark = trim((string) preg_replace('/\s+/u', ' ', $typedSignature));
        if (mb_strlen($mark) < 2 || mb_strlen($mark) > 100 || ! preg_match('/^\p{L}[\p{L}\p{M} .\'’-]*$/u', $mark)) {
            throw new WorkflowRuleBroken('Type your initials or your full name as your signature: letters only, at least two.');
        }

        return DB::transaction(function () use ($artefact, $chair, $mark, $comment, $ip, $userAgent): BoardSignature {
            $artefact = $this->lock($artefact);
            $this->ensure($chair, 'sign', $artefact);

            $assessment = $artefact->assessment;
            $signed = $artefact->approvedVersion()->first()
                ?? throw new WorkflowRuleBroken('There is no approved version of the ODP to sign.');

            $signature = BoardSignature::query()->create([
                'artefact_id' => $artefact->id,
                'document_id' => $signed->id,
                'signed_by' => $chair->id,
                'signed_name' => $chair->name,
                'signature_method' => 'typed',
                'signature_text' => $mark,
                'document_sha256' => $signed->sha256,
                'comment' => $comment !== null && trim($comment) !== '' ? trim($comment) : null,
                'ip' => $ip,
                'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 500) : null,
                'signed_at' => now(),
            ]);

            Audit::record($chair, $artefact->kind->value.'.signed_by_board', $artefact, $assessment, payload: [
                'signed_name' => $signature->signed_name,
                'signature' => $mark,
                'version' => $signed->versionNumber(),
                'document_sha256' => $signature->document_sha256,
                'comment' => $signature->comment,
            ]);

            $name = SubmitForApproval::label($artefact);
            Notify::send(
                Notify::assessorsOf($assessment)->push($artefact->submitter, $artefact->approver),
                new WorkflowNotice(
                    "Validated by the board: {$name}, {$assessment->movement->name}",
                    "{$chair->name} ({$chair->title}) signed the ".SubmitForApproval::inSentence($name).' on behalf of the board.'.($signature->comment ? ' Comment: '.$signature->comment : ''),
                    Notify::link($assessment), 'good',
                ),
            );

            return $signature;
        });
    }
}
