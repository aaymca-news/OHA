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

/**
 * Keeps an assessment's ODP tasks in step with its ODP: when a newer version of the
 * ODP has been uploaded (or taken from Google Drive), its tasks are read again.
 *
 * A date a person corrected is kept for the same task in the newer version; tasks a
 * person typed in are never touched. When the newer version cannot be read, the
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

    /** The ODP version the tasks come from: the newest one uploaded. */
    public static function source(Assessment $assessment): ?Document
    {
        $odp = $assessment->odp()->first();

        return $odp === null ? null : Document::query()
            ->where('artefact_id', $odp->id)->where('purpose', DocumentPurpose::Uploaded)
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
            if ($result['tasks'] !== []) {
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
        // Once the ODP is signed, progress is tracked in the platform only: the sheet's
        // own "task completed" column no longer counts (decided 10 Nov 2026).
        $signed = $assessment->odp()->first()?->signature()->exists() ?? false;
        $assessment->odpTasks()->where('source', OdpTask::FROM_ODP)->delete();

        foreach ($tasks as $i => $task) {
            $row = $task + ['assessment_id' => $assessment->id, 'document_id' => $document->id, 'source' => OdpTask::FROM_ODP, 'sort_order' => $i];
            if ($signed) {
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

    private static function key(?string $ref, string $task): string
    {
        return ($ref ?? '').'|'.mb_strtolower((string) preg_replace('/\W+/u', '', $task));
    }
}
