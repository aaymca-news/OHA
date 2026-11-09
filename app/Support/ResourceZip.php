<?php

namespace App\Support;

use App\Enums\ArtefactKind;
use App\Models\Assessment;
use App\Models\Evidence;
use App\Models\User;
use App\Oha\EvidenceQuestions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * One ZIP file of what Resources offers for some assessments, as this user may see it:
 * the approved OHA form, report and ODP, and the evidence library (files, and the links
 * listed in a text file). One folder per assessment. Each download is recorded.
 */
final class ResourceZip
{
    /**
     * @param  Collection<int, Assessment>  $assessments
     * @return array{path: string, files: int}
     */
    public function build(User $user, Collection $assessments): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'ohazip');
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the ZIP file.');
        }

        $files = 0;
        foreach ($assessments as $assessment) {
            $folder = $this->safe($assessment->movement->name.' - '.$assessment->period_label);
            $added = $this->addAssessment($zip, $user, $assessment, $folder);
            if ($added > 0) {
                Audit::record($user, 'file.downloaded', $assessment, $assessment, payload: ['file' => "{$folder} (ZIP, {$added} files)"]);
            }
            $files += $added;
        }

        if ($files === 0) {
            $zip->addFromString('README.txt', "Nothing approved or added yet.\n");
        }
        $zip->close();

        return ['path' => $path, 'files' => $files];
    }

    private function addAssessment(ZipArchive $zip, User $user, Assessment $assessment, string $folder): int
    {
        $added = 0;
        $artefacts = $assessment->artefacts()->with(['approvedVersion', 'uploads' => fn ($q) => $q->whereNotNull('approved_at')->latest('id')])->get()
            ->keyBy(fn ($a) => $a->kind->value);

        $form = $artefacts[ArtefactKind::Form->value] ?? null;
        $upload = $form?->uploads->first();
        if ($upload !== null && Gate::forUser($user)->allows('download', $upload)) {
            $file = $upload->shownFile();
            $added += $this->addFile($zip, $file['disk'], $file['path'], "{$folder}/OHA form - {$file['name']}");
        }
        foreach (['report' => 'Report', 'odp' => 'ODP'] as $kind => $label) {
            $version = ($artefacts[$kind] ?? null)?->approvedVersion;
            if ($version !== null && Gate::forUser($user)->allows('download', $version)) {
                $added += $this->addFile($zip, $version->disk, $version->path, "{$folder}/{$label} - version {$version->versionNumber()} - {$version->original_name}");
            }
        }

        if (Gate::forUser($user)->allows('viewLibrary', [Evidence::class, $assessment->movement])) {
            $evidence = $assessment->evidence()->with('category')->orderBy('id')->get();
            $links = [];
            $questions = DB::table('evidence_questions')->whereIn('evidence_id', $evidence->pluck('id'))->get()->groupBy('evidence_id');
            foreach ($evidence as $e) {
                $sub = $folder.'/Evidence/'.$this->safe($e->category !== null ? $e->category->form_order.'. '.$e->category->name : 'General');
                $proves = collect($questions[$e->id] ?? [])->map(fn (object $q): string => EvidenceQuestions::shown($q->question_code).' '.EvidenceQuestions::label($q->question_code))->implode('; ');
                if ($e->isLink()) {
                    $links[] = "{$e->title}\n  {$e->url}".($proves !== '' ? "\n  Proves: {$proves}" : '')."\n  Folder: ".($e->category->name ?? 'General');

                    continue;
                }
                $added += $this->addFile($zip, (string) $e->disk, (string) $e->path, $sub.'/'.$this->safe($e->title).'.'.$e->format);
            }
            if ($links !== []) {
                $zip->addFromString("{$folder}/Evidence/Links.txt", implode("\n\n", $links)."\n");
                $added++;
            }
        }

        return $added;
    }

    /** Adds a stored file under $name, numbering it if the name is taken. 1 if added. */
    private function addFile(ZipArchive $zip, string $disk, string $path, string $name): int
    {
        if (! Storage::disk($disk)->exists($path)) {
            return 0;
        }
        $unique = $name;
        for ($n = 2; $zip->locateName($unique) !== false; $n++) {
            $unique = preg_replace('/(\.[^.\/]+)?$/', " ({$n})$1", $name, 1);
        }
        // Read from disk when the ZIP is written, not held in memory.
        $zip->addFile(Storage::disk($disk)->path($path), (string) $unique);

        return 1;
    }

    /** A name safe for a folder or file in the ZIP. */
    private function safe(string $name): string
    {
        return trim(Str::limit((string) preg_replace('/[\\\\\/:*?"<>|]+/', '-', $name), 120, ''));
    }
}
