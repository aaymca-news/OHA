<?php

namespace App\Actions\Oha\Concerns;

use App\Enums\ArtefactState;
use App\Models\Artefact;
use App\Models\Category;
use App\Models\FormFinding;
use App\Models\FormUpload;
use App\Models\User;
use App\Oha\CheckResult;
use App\Oha\FormDefinition;
use App\Oha\ReadForm;
use App\Support\ChangedAfterApproval;
use Illuminate\Support\Collection;

/**
 * What an OHA form check leaves on the record: the answers, what was read from the
 * file besides them, the findings, and the form's state. Shared by a new upload and by
 * answers typed in the platform, which check the same file again.
 */
trait RecordsFormChecks
{
    /**
     * @return array<string, mixed>
     */
    private function meta(?ReadForm $read, CheckResult $result): array
    {
        if ($read === null) {
            return [];
        }

        return [
            'notes' => array_filter($read->notes, fn ($v) => $v !== null),
            'comments' => array_map(fn ($c) => $c['text'], $read->comments),
            'cover' => $result->cover,
            'sheet_names' => $read->sheetNames,
            'missing_sheets' => $read->missingCategorySheets(),
            'points_at_stake' => $result->pointsAtStake,
            // Answers written in words, and what they were read as; and answers in the wrong unit.
            'interpreted' => $read->interpreted,
            'wrong_units' => $read->wrongUnits,
            // Each question's wording, to label the boxes where a missing answer is typed.
            'labels' => array_map(fn ($l) => $l['text'], $read->locations),
            // Where each answer sits in the workbook, so a typed answer is written into the right cell.
            'cells' => $this->cells($read),
        ];
    }

    /**
     * The cell of every answer, improvement comment and sign-off line, as "sheet" and "cell".
     *
     * @return array{answers: array<string, array{sheet: string, cell: string}>, comments: array<string, array{sheet: string, cell: string}>, signoff: array<string, array{sheet: string, cell: string}>}
     */
    private function cells(ReadForm $read): array
    {
        $answers = [];
        foreach ($read->locations as $code => $where) {
            $column = $where['sheet'] === $read->generalSheet ? FormDefinition::GENERAL_COLUMNS['answer'] : FormDefinition::CATEGORY_COLUMNS['answer'];
            $answers[$code] = ['sheet' => $where['sheet'], 'cell' => $column.$where['row']];
        }

        $comments = [];
        foreach ($read->comments as $code => $comment) {
            $sheet = $read->categorySheets[$code] ?? null;
            if ($sheet !== null && ($comment['row'] ?? null) !== null) {
                $comments[$code] = ['sheet' => $sheet, 'cell' => 'D'.$comment['row']];
            }
        }

        $signoff = [];
        foreach ($read->signoff as $label => $line) {
            if ($read->generalSheet !== null && $line['row'] > 0) {
                $signoff[$label] = ['sheet' => $read->generalSheet, 'cell' => 'D'.$line['row']];
            }
        }

        return ['answers' => $answers, 'comments' => $comments, 'signoff' => $signoff];
    }

    /**
     * Records the findings of a check. A note that closed off a gap stays with that gap
     * while the gap is still there.
     *
     * @param  Collection<int, FormFinding>|null  $previous
     */
    private function recordFindings(FormUpload $upload, CheckResult $result, ?Collection $previous = null): void
    {
        $categories = Category::query()->pluck('id', 'code');
        $resolved = ($previous ?? collect())->whereNotNull('resolved_at')->keyBy(fn (FormFinding $f) => $f->ref ?? $f->message);

        foreach ($result->findings as $finding) {
            $kept = $resolved[$finding->ref ?? $finding->message] ?? null;
            $upload->findings()->create([
                'severity' => $finding->severity,
                'rule' => $finding->rule->value,
                'ref' => $finding->ref,
                'question_code' => $finding->questionCode,
                'category_id' => $finding->categoryCode !== null ? $categories[$finding->categoryCode] ?? null : null,
                'location' => $finding->location !== '' ? $finding->location : null,
                'message' => $finding->message,
                'hint' => $finding->hint !== '' ? $finding->hint : null,
                'points_at_stake' => $finding->pointsAtStake,
                'dqa_dimension' => $finding->rule->dimension(),
                'resolved_by' => $kept?->resolved_by,
                'resolved_at' => $kept?->resolved_at,
                'resolved_note' => $kept?->resolved_note,
            ]);
        }
    }

    /**
     * The form's state after a check: ready to submit, or refused. A form that was
     * approved goes back to the Administrators, who are told. Any earlier acknowledgement
     * of gaps is for the earlier answers, so it does not carry over.
     */
    private function settle(Artefact $form, CheckResult $result, User $by, string $change): void
    {
        $from = $form->state;
        $form->update([
            'state' => $result->ok ? ArtefactState::Ready : ArtefactState::RulesFailed,
            'gap_ack' => false,
            'gap_ack_reason' => null,
        ]);

        if ($from === ArtefactState::Approved) {
            ChangedAfterApproval::tell($form, $by, "{$by->name} {$change}");
        }
    }
}
