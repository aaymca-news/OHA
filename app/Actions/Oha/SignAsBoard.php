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
use App\Support\FileVault;
use App\Support\Notify;
use Illuminate\Support\Facades\DB;

/**
 * The Board Chairperson signs the approved ODP online, which validates it.
 *
 * Kept as evidence: the drawn signature (a PNG on the private disk), the name as
 * they typed it, when, from where, and the exact version signed: the last approved
 * one, with its file's SHA-256 fingerprint. From then on the ODP is frozen, and its
 * implementation is followed in Stage 2.
 * Signing never holds the Secretariat back: it only changes the document's label.
 */
final class SignAsBoard
{
    use EnforcesPolicy, LocksArtefact;

    /** A drawn signature is a small image; anything larger is not one. */
    private const MAX_SIGNATURE_BYTES = 300 * 1024;

    public function handle(Artefact $artefact, User $chair, string $signedName, string $signatureDataUrl, bool $confirmed, ?string $comment = null, ?string $ip = null, ?string $userAgent = null): BoardSignature
    {
        if (! $confirmed) {
            throw new WorkflowRuleBroken('Tick the box to confirm you have read the document and validate it on behalf of the board.');
        }
        if (mb_strlen(trim($signedName)) < 3) {
            throw new WorkflowRuleBroken('Type your full name as your signature.');
        }
        $png = $this->decodePng($signatureDataUrl);

        return DB::transaction(function () use ($artefact, $chair, $signedName, $png, $comment, $ip, $userAgent): BoardSignature {
            $artefact = $this->lock($artefact);
            $this->ensure($chair, 'sign', $artefact);

            $assessment = $artefact->assessment;
            $signed = $artefact->approvedVersion()->first()
                ?? throw new WorkflowRuleBroken('There is no approved version of the ODP to sign.');
            $temp = tempnam(sys_get_temp_dir(), 'sig');
            file_put_contents($temp, $png);
            $stored = FileVault::store($temp, "signatures/{$assessment->id}", 'png');
            @unlink($temp);

            $signature = BoardSignature::query()->create([
                'artefact_id' => $artefact->id,
                'document_id' => $signed->id,
                'signed_by' => $chair->id,
                'signed_name' => trim($signedName),
                'signature_disk' => $stored['disk'],
                'signature_path' => $stored['path'],
                'signature_sha256' => $stored['sha256'],
                'document_sha256' => $signed->sha256,
                'comment' => $comment !== null && trim($comment) !== '' ? trim($comment) : null,
                'ip' => $ip,
                'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 500) : null,
                'signed_at' => now(),
            ]);

            Audit::record($chair, $artefact->kind->value.'.signed_by_board', $artefact, $assessment, payload: [
                'signed_name' => $signature->signed_name,
                'version' => $signed->versionNumber(),
                'document_sha256' => $signature->document_sha256,
                'comment' => $signature->comment,
            ]);

            $name = SubmitForApproval::label($artefact);
            Notify::send(
                Notify::assessorsOf($assessment)->push($artefact->submitter, $artefact->approver),
                new WorkflowNotice(
                    "Validated by the board: {$name}, {$assessment->movement->name}",
                    "{$chair->name} ({$chair->title}) signed the ".lcfirst($name).' on behalf of the board.'.($signature->comment ? ' Comment: '.$signature->comment : ''),
                    Notify::link($assessment), 'good',
                ),
            );

            return $signature;
        });
    }

    private function decodePng(string $dataUrl): string
    {
        if (! str_starts_with($dataUrl, 'data:image/png;base64,')) {
            throw new WorkflowRuleBroken('Draw your signature in the box before signing.');
        }

        $png = base64_decode(substr($dataUrl, strlen('data:image/png;base64,')), true);

        if ($png === false || ! str_starts_with($png, "\x89PNG\r\n\x1a\n") || strlen($png) > self::MAX_SIGNATURE_BYTES) {
            throw new WorkflowRuleBroken('The signature could not be read. Clear it and draw it again.');
        }

        return $png;
    }
}
