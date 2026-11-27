<?php

namespace App\Queries;

use App\Models\Assessment;
use App\Models\Evidence;
use App\Models\FormUpload;
use App\Models\User;
use App\Oha\Answer;
use App\Oha\EvidenceQuestions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * An assessment's evidence library, read for showing: each document with the form
 * questions and ODP tasks it backs, and the checklist of "Yes" answers that call for a
 * document, with what has been supplied for each.
 */
final class EvidenceLibrary
{
    /**
     * Each document, newest first, with its links.
     *
     * @return Collection<int, array{evidence: Evidence, questions: list<string>, tasks: list<string>}>
     */
    public function for(Assessment $assessment): Collection
    {
        return $this->withLinks(Evidence::query()->where('assessment_id', $assessment->id)
            ->with('category', 'uploader', 'sharer')->latest('uploaded_at')->latest('id')->get());
    }

    /**
     * The Area Resource Repository (27 Nov 2026): every document shared from a movement's
     * library, for everyone, newest first; in one area (a category's code, or "general"),
     * from one movement, or with words in its title, when asked. Only what the document is:
     * its title, file, area and movement; not what it says about the movement's form.
     *
     * @return Collection<int, Evidence>
     */
    public function shared(?string $area = null, ?int $movementId = null, string $search = ''): Collection
    {
        return Evidence::query()->whereNotNull('shared_at')
            ->with('category', 'assessment.movement')
            ->when($area === 'general', fn ($q) => $q->whereNull('category_id'))
            ->when($area !== null && $area !== 'general', fn ($q) => $q->whereHas('category', fn ($c) => $c->where('code', $area)))
            ->when($movementId !== null, fn ($q) => $q->whereHas('assessment', fn ($a) => $a->where('movement_id', $movementId)))
            ->when($search !== '', fn ($q) => $q->whereRaw('lower(title) like ?', ['%'.mb_strtolower($search).'%']))
            ->latest('shared_at')->latest('id')->get();
    }

    /**
     * The answers this user may see for the assessment: the approved form for everyone,
     * the newer working one for its assessors and the Administrators. Null before any.
     *
     * @return array<string, mixed>|null
     */
    public function answersFor(User $user, Assessment $assessment): ?array
    {
        $form = $assessment->form()->first();
        if ($form === null) {
            return null;
        }
        $seesDrafts = Gate::forUser($user)->allows('viewDrafts', $assessment);
        $upload = $form->uploads()->latest('id')->get()->first(fn (FormUpload $u) => $seesDrafts || $u->isApproved());

        return $upload?->answers;
    }

    /**
     * Category code => the evidence questions answered "Yes", each with exactly what is
     * needed, the kind of evidence it takes (document, link or either), and the evidence
     * linked to it. Questions answered otherwise are left out: there is nothing to prove.
     *
     * @param  array<string, mixed>|null  $answers
     * @param  Collection<int, array{evidence: Evidence, questions: list<string>, tasks: list<string>}>  $library
     * @return array<string, list<array{code: string, label: string, needed: string, accepts: string, documents: list<Evidence>}>>
     */
    public function checklist(?array $answers, Collection $library): array
    {
        if ($answers === null) {
            return [];
        }

        $checklist = [];
        foreach (EvidenceQuestions::byCategory() as $category => $questions) {
            foreach ($questions as $code => $label) {
                if (! Answer::isYes($answers[$code] ?? null)) {
                    continue;
                }
                $checklist[$category][] = [
                    'code' => $code,
                    'label' => $label,
                    'needed' => (string) EvidenceQuestions::needed($code),
                    'accepts' => EvidenceQuestions::accepts($code),
                    'documents' => $library->filter(fn (array $row) => in_array($code, $row['questions'], true))->pluck('evidence')->values()->all(),
                ];
            }
        }

        return $checklist;
    }

    /**
     * ODP task number => the documents linked to it, in task order.
     *
     * @param  Collection<int, array{evidence: Evidence, questions: list<string>, tasks: list<string>}>  $library
     * @return array<string, list<Evidence>>
     */
    public function byOdpTask(Collection $library): array
    {
        $tasks = [];
        foreach ($library as $row) {
            foreach ($row['tasks'] as $task) {
                $tasks[$task][] = $row['evidence'];
            }
        }
        uksort($tasks, fn (string $a, string $b) => array_map('intval', explode('.', $a)) <=> array_map('intval', explode('.', $b)));

        return $tasks;
    }

    /**
     * @param  Collection<int, Evidence>  $evidence
     * @return Collection<int, array{evidence: Evidence, questions: list<string>, tasks: list<string>}>
     */
    private function withLinks(Collection $evidence): Collection
    {
        $ids = $evidence->pluck('id');
        $questions = DB::table('evidence_questions')->whereIn('evidence_id', $ids)->get()->groupBy('evidence_id');
        $tasks = DB::table('evidence_odp_tasks')->whereIn('evidence_id', $ids)->get()->groupBy('evidence_id');

        // Questions in the form's order.
        $order = array_flip(array_keys(EvidenceQuestions::LABELS));

        return $evidence->map(fn (Evidence $e) => [
            'evidence' => $e,
            'questions' => array_values(collect($questions[$e->id] ?? [])->map(fn (object $row): string => $row->question_code)
                ->sortBy(fn (string $code) => $order[$code] ?? PHP_INT_MAX)->all()),
            'tasks' => array_values(collect($tasks[$e->id] ?? [])->map(fn (object $row): string => $row->task)
                ->sortBy(fn (string $t) => array_map('intval', explode('.', $t)))->all()),
        ]);
    }
}
