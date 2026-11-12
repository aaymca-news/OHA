<?php

namespace App\Support;

use App\Enums\DocumentFormat;
use App\Enums\DocumentPurpose;
use App\Models\Assessment;
use App\Models\Document;
use App\Models\OdpTask;
use App\Oha\OdpTaskReader;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Keeps an assessment's ODP tasks in step with its ODP: when a newer version of the
 * ODP has been uploaded (or taken from Google Drive), its tasks are read again.
 *
 * In Stage 1 the tasks are read afresh (a date a person corrected is kept for the same
 * task). Once the assessment is closed, a revision made during a follow-up is matched
 * against the plan instead (decided 12 Nov 2026), so every task keeps its history: a task
 * still there keeps its status, notes and evidence (its wording and who is responsible
 * follow the revision); a new one is added; one no longer there is removed (kept for the
 * record, out of the plan); what changed is kept with the revision. Tasks a person typed
 * in, or carried over, are never touched. When the newer version cannot be read, the
 * tasks already there stay, and why it could not be read is noted.
 */
final class OdpTimeline
{
    /**
     * Each movement's latest assessment with an ODP (or tasks typed in for it), by
     * movement name: the ODPs whose tasks are followed on Timelines and the dashboards.
     *
     * @param  list<int>|null  $movementIds  null for every movement
     * @return Collection<int, Assessment>
     */
    public static function assessments(?array $movementIds = null): Collection
    {
        return Assessment::query()->with(['movement', 'odpReadDocument', 'workItem'])
            ->when($movementIds !== null, fn ($q) => $q->whereIn('movement_id', $movementIds))
            ->latestPerMovement(fn ($q) => $q->where(fn ($q) => $q
                ->whereHas('odp.documents', fn ($d) => $d->where('purpose', DocumentPurpose::Uploaded))->orWhereHas('odpTasks')))
            ->get()
            ->sortBy(fn (Assessment $a) => $a->movement->name)->values();
    }

    /** The ODP version the tasks come from: the newest one uploaded, or revised during a follow-up. */
    public static function source(Assessment $assessment): ?Document
    {
        $odp = $assessment->odp()->first();

        return $odp === null ? null : Document::query()
            ->where('artefact_id', $odp->id)->whereIn('purpose', [DocumentPurpose::Uploaded, DocumentPurpose::Revision])
            ->latest('id')->first();
    }

    /**
     * Reads the tasks again if a newer ODP version arrived since they were last read.
     */
    public static function refresh(Assessment $assessment): void
    {
        $document = self::source($assessment);
        if ($document === null || $assessment->odp_read_document_id === $document->id) {
            return;
        }

        if ($document->format !== DocumentFormat::Xlsx) {
            $assessment->update([
                'odp_read_document_id' => $document->id,
                'odp_read_problem' => 'This ODP is a '.($document->format === DocumentFormat::Docx ? 'Word' : 'PDF').' file, so its tasks cannot be read automatically. Type them in here.',
            ]);

            return;
        }

        $result = self::read($document, ($assessment->assessed_on ?? $assessment->opened_at)?->year);

        DB::transaction(function () use ($assessment, $document, $result): void {
            if ($result['tasks'] !== [] && $assessment->isClosed()) {
                $changes = self::revise($assessment, $document, $result['tasks']);
                if ($document->purpose === DocumentPurpose::Revision) {
                    $document->forceFill(['changes' => $changes])->save();
                }
            } elseif ($result['tasks'] !== []) {
                self::replace($assessment, $document, $result['tasks']);
            }
            $assessment->update(['odp_read_document_id' => $document->id, 'odp_read_problem' => $result['problem']]);
        });
        $assessment->unsetRelation('odpReadDocument')->unsetRelation('odpTasks');
    }

    /**
     * @return array{tasks: list<array{ref: string|null, priority: string|null, task: string, deadline_text: string|null, due_on: string|null, date_guessed: bool, responsible: string|null, done: bool}>, problem: string|null}
     */
    private static function read(Document $document, ?int $year): array
    {
        $stream = Storage::disk($document->disk)->readStream($document->path);
        if ($stream === null) {
            return ['tasks' => [], 'problem' => 'The ODP file could not be found.'];
        }

        $copy = tempnam(sys_get_temp_dir(), 'odp');
        try {
            $out = fopen((string) $copy, 'wb');
            stream_copy_to_stream($stream, $out);
            fclose($out);

            return OdpTaskReader::read((string) $copy, $year);
        } finally {
            fclose($stream);
            @unlink((string) $copy);
        }
    }

    /**
     * @param  list<array{ref: string|null, priority: string|null, task: string, deadline_text: string|null, due_on: string|null, date_guessed: bool, responsible: string|null, done: bool}>  $tasks
     */
    private static function replace(Assessment $assessment, Document $document, array $tasks): void
    {
        $previous = $assessment->odpTasks()->where('source', OdpTask::FROM_ODP)->get();
        $byKey = fn ($tasks) => $tasks->keyBy(fn (OdpTask $t) => self::key($t->ref, $t->task));
        // A date a person corrected, and progress reported in Stage 2, outlive a newer version.
        $corrected = $byKey($previous->whereNotNull('edited_at'));
        $reported = $byKey($previous->filter(fn (OdpTask $t) => $t->reported_at !== null || $t->confirmed_at !== null));
        // Once the assessment is closed, progress is tracked in the platform only: the
        // sheet's own "task completed" column no longer counts (decided 10 Nov 2026).
        $closed = $assessment->isClosed();
        $assessment->odpTasks()->where('source', OdpTask::FROM_ODP)->delete();

        foreach ($tasks as $i => $task) {
            $row = $task + ['assessment_id' => $assessment->id, 'document_id' => $document->id, 'source' => OdpTask::FROM_ODP, 'sort_order' => $i];
            if ($closed) {
                $row['done'] = false;
            }
            $key = self::key($task['ref'], $task['task']);
            if (($before = $corrected[$key] ?? null) !== null) {
                $row = array_merge($row, [
                    'due_on' => $before->due_on?->toDateString(),
                    'date_guessed' => false,
                    'responsible' => $before->responsible,
                    'done' => $before->done || $row['done'],
                    'edited_by' => $before->edited_by,
                    'edited_at' => $before->edited_at,
                ]);
            }
            if (($before = $reported[$key] ?? null) !== null) {
                $row = array_merge($row, [
                    'done' => $before->done,
                    'progress' => $before->progress,
                    'progress_note' => $before->progress_note,
                    'evidence_id' => $before->evidence_id,
                    'reported_by' => $before->reported_by,
                    'reported_at' => $before->reported_at,
                    'confirmed_by' => $before->confirmed_by,
                    'confirmed_at' => $before->confirmed_at,
                    'returned_reason' => $before->returned_reason,
                ]);
            }
            OdpTask::query()->create($row);
        }
    }

    /**
     * Matches a revised ODP's tasks against the plan, in place (see the class comment). A
     * task is the same task when its number and wording match or, failing that, when its
     * number alone matches exactly one task on each side (it was reworded). A removed task
     * that comes back is restored. Returns what changed, in words.
     *
     * @param  list<array{ref: string|null, priority: string|null, task: string, deadline_text: string|null, due_on: string|null, date_guessed: bool, responsible: string|null, done: bool}>  $tasks
     * @return array{kind: string, added: list<string>, removed: list<string>, reworded: list<string>, responsible: list<string>}
     */
    private static function revise(Assessment $assessment, Document $document, array $tasks): array
    {
        $existing = OdpTask::query()->withoutGlobalScope(OdpTask::IN_PLAN)
            ->where('assessment_id', $assessment->id)->where('source', OdpTask::FROM_ODP)
            ->orderBy('sort_order')->orderBy('id')->get();
        $byKey = $existing->keyBy(fn (OdpTask $t) => self::key($t->ref, $t->task));
        $label = fn (?string $ref, string $task): string => ($ref !== null && $ref !== '' ? $ref.' · ' : '').Str::limit($task, 120);
        $changes = ['kind' => 'tasks', 'added' => [], 'removed' => [], 'reworded' => [], 'responsible' => []];
        /** @var array<int, true> $matched by task id */
        $matched = [];
        /** @var array<int, OdpTask> $found by the revision's row */
        $found = [];

        // First the exact matches (number and wording), so a reworded task never takes another's place.
        foreach ($tasks as $i => $task) {
            $old = $byKey[self::key($task['ref'], $task['task'])] ?? null;
            if ($old !== null && ! isset($matched[$old->id])) {
                $found[$i] = $old;
                $matched[$old->id] = true;
            }
        }
        // Then by number alone: exactly one task with that number on each side, not matched yet.
        foreach ($tasks as $i => $task) {
            if (isset($found[$i]) || $task['ref'] === null || $task['ref'] === '') {
                continue;
            }
            $sameRef = $existing->filter(fn (OdpTask $t) => $t->ref === $task['ref'] && ! isset($matched[$t->id]));
            $alsoNew = collect($tasks)->filter(fn (array $t, int $j) => $t['ref'] === $task['ref'] && ! isset($found[$j]));
            if ($sameRef->count() === 1 && $alsoNew->count() === 1) {
                $found[$i] = $sameRef->first();
                $matched[$found[$i]->id] = true;
            }
        }

        foreach ($tasks as $i => $task) {
            $old = $found[$i] ?? null;
            if ($old === null) {
                OdpTask::query()->create([
                    'assessment_id' => $assessment->id, 'document_id' => $document->id, 'source' => OdpTask::FROM_ODP, 'sort_order' => $i,
                    'ref' => $task['ref'], 'priority' => $task['priority'], 'task' => $task['task'], 'responsible' => $task['responsible'],
                    'deadline_text' => $task['deadline_text'], 'due_on' => $task['due_on'], 'date_guessed' => $task['date_guessed'],
                    // Where it stands is set at the follow-up meetings, never by the sheet.
                    'done' => false,
                ]);
                $changes['added'][] = $label($task['ref'], $task['task']);

                continue;
            }
            if (self::key(null, $old->task) !== self::key(null, $task['task'])) {
                $changes['reworded'][] = $label($task['ref'], $task['task']).' (was: '.Str::limit($old->task, 80).')';
            }
            if ($task['responsible'] !== null && $old->responsible !== $task['responsible']) {
                $changes['responsible'][] = $label($task['ref'], $task['task']).': '.$task['responsible'].($old->responsible !== null ? ' (was: '.$old->responsible.')' : '');
            }
            if ($old->isRemoved()) {
                $changes['added'][] = $label($task['ref'], $task['task']).' (back in the plan)';
            }
            // Its wording, number, priority and who is responsible follow the revision; where it stands, its notes and evidence stay.
            $old->forceFill([
                'document_id' => $document->id, 'sort_order' => $i, 'ref' => $task['ref'], 'priority' => $task['priority'], 'task' => $task['task'],
                'responsible' => $task['responsible'] ?? $old->responsible, 'deadline_text' => $task['deadline_text'],
                'removed_at' => null, 'removed_in_document_id' => null,
            ])->save();
        }

        foreach ($existing as $old) {
            if (! isset($matched[$old->id]) && ! $old->isRemoved()) {
                $old->forceFill(['removed_at' => now(), 'removed_in_document_id' => $document->id])->save();
                $changes['removed'][] = $label($old->ref, $old->task);
            }
        }

        return $changes;
    }

    private static function key(?string $ref, string $task): string
    {
        return ($ref ?? '').'|'.mb_strtolower((string) preg_replace('/\W+/u', '', $task));
    }
}
