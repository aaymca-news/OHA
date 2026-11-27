<?php

namespace App\Queries;

use App\Enums\ArtefactKind;
use App\Models\Artefact;
use App\Models\Assessment;
use App\Models\BoardSignature;
use App\Models\Document;
use App\Models\FormUpload;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Stage 1 of each assessment as its three approved documents (decided 27 Nov 2026): the
 * Excel assessment form, the report and the ODP, each with when it was approved and its
 * download; then when Stage 1 closed and the follow-ups began. Only what is approved and
 * this person may download: never the checks, the drafts or what is missing. AAYMCA staff
 * also get a link to read each one in its assessment.
 */
final class StageOneDocuments
{
    /**
     * @param  EloquentCollection<int, Assessment>  $assessments  newest first
     * @return list<array{assessment: Assessment, steps: list<array{key: string, title: string, icon: string, name: string|null, approved_at: CarbonInterface|null, download: string|null, open: string|null}>, signature: BoardSignature|null, closed_at: CarbonInterface|null}>
     */
    public function for(User $user, EloquentCollection $assessments): array
    {
        $gate = Gate::forUser($user);
        $staff = $user->isSecretariat();
        $assessments->load([
            'artefacts.signature',
            'artefacts.uploads' => fn ($q) => $q->whereNotNull('approved_at')->latest('id'),
            'artefacts.approvedVersion',
        ]);

        $entries = [];
        foreach ($assessments as $assessment) {
            /** @var Collection<string, Artefact> $artefacts */
            $artefacts = $assessment->artefacts->keyBy(fn (Artefact $a) => $a->kind->value);
            $form = ($artefacts[ArtefactKind::Form->value] ?? null)?->uploads->first();
            $report = ($artefacts[ArtefactKind::Report->value] ?? null)?->approvedVersion;
            $odp = $artefacts[ArtefactKind::Odp->value] ?? null;

            // Each only if this person may download it (the policies decide, as for the file itself).
            $form = $form !== null && $gate->allows('download', $form) ? $form : null;
            $report = $report !== null && $gate->allows('download', $report) ? $report : null;
            $odpVersion = $odp?->approvedVersion;
            $odpVersion = $odpVersion !== null && $gate->allows('download', $odpVersion) ? $odpVersion : null;

            $entries[] = [
                'assessment' => $assessment,
                'steps' => [
                    $this->step('form', 'Excel assessment form', 'table_view', $form, $form !== null ? route('downloads.form', $form) : null,
                        $staff && $form !== null ? route('assessments.show', ['assessment' => $assessment, 'tab' => 'form', 'upload' => $form->id]).'#form-preview' : null),
                    $this->step('report', 'Report', 'description', $report, $report !== null ? route('downloads.document', $report) : null,
                        $staff && $report !== null ? route('assessments.show', ['assessment' => $assessment, 'tab' => 'report', 'version' => $report->id]) : null),
                    $this->step('odp', 'ODP', 'checklist', $odpVersion, $odpVersion !== null ? route('downloads.document', $odpVersion) : null,
                        $staff && $odpVersion !== null ? route('assessments.show', ['assessment' => $assessment, 'tab' => 'odp', 'version' => $odpVersion->id]) : null),
                ],
                'signature' => $odpVersion !== null ? $odp?->signature : null,
                'closed_at' => $assessment->closed_at,
            ];
        }

        return $entries;
    }

    /**
     * @return array{key: string, title: string, icon: string, name: string|null, approved_at: CarbonInterface|null, download: string|null, open: string|null}
     */
    private function step(string $key, string $title, string $icon, FormUpload|Document|null $file, ?string $download, ?string $open): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'icon' => $icon,
            'name' => match (true) {
                $file instanceof Document => $file->original_name.' · version '.$file->versionNumber(),
                $file instanceof FormUpload => $file->original_name,
                default => null,
            },
            'approved_at' => $file?->approved_at,
            'download' => $download,
            'open' => $open,
        ];
    }
}
