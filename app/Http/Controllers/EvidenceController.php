<?php

namespace App\Http\Controllers;

use App\Actions\Evidence\MoveEvidence;
use App\Actions\Evidence\RemoveEvidence;
use App\Actions\Evidence\ShareEvidence;
use App\Actions\Evidence\UploadEvidence;
use App\Models\Assessment;
use App\Models\Category;
use App\Models\Evidence;
use App\Models\Movement;
use App\Oha\EvidenceQuestions;
use App\Queries\EvidenceLibrary;
use App\Support\Audit;
use App\Support\UploadLimit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A movement's evidence library: for each assessment, its supporting documents in ten
 * groups (the nine OHA categories and General), the checklist of "Yes" answers that call
 * for a document, and adding, sharing and removing documents. The actions check the rules.
 */
class EvidenceController extends Controller
{
    public function index(Request $request, Movement $movement, EvidenceLibrary $library): View
    {
        Gate::authorize('viewLibrary', [Evidence::class, $movement]);
        $user = $request->user();

        $assessments = $movement->assessments()->latest('assessed_on')->latest('id')->get();
        $assessment = $assessments->firstWhere('id', (int) $request->query('assessment')) ?? $assessments->first();
        $documents = $assessment !== null ? $library->for($assessment) : collect();
        // "Add" next to a missing item opens the form ready for that question.
        $for = EvidenceQuestions::label((string) $request->query('for')) !== null ? (string) $request->query('for') : null;

        return view('evidence.index', [
            'for' => $for,
            'movement' => $movement,
            'assessments' => $assessments,
            'assessment' => $assessment,
            'documents' => $documents,
            'categories' => Category::query()->orderBy('form_order')->get(),
            'questions' => EvidenceQuestions::byCategory(),
            'checklist' => $assessment !== null ? $library->checklist($library->answersFor($user, $assessment), $documents) : [],
            'canUpload' => $assessment !== null && Gate::forUser($user)->allows('upload', [Evidence::class, $assessment]),
            'canShareNew' => $user->isAdmin() || $user->isGeneralSecretaryOf($movement),
        ]);
    }

    public function store(Request $request, Movement $movement, UploadEvidence $upload): RedirectResponse
    {
        $data = $request->validate([
            'assessment_id' => ['required', 'integer'],
            'kind' => ['required', 'in:file,link'],
            'file' => ['required_if:kind,file', 'nullable', 'file', 'max:'.UploadLimit::kb()],
            'url' => ['required_if:kind,link', 'nullable', 'string', 'max:2000'],
            'title' => ['nullable', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'exists:categories,code'],
            'questions' => ['array'],
            'questions.*' => ['string', 'max:10'],
            'tasks' => ['nullable', 'string', 'max:200'],
            'share' => ['boolean'],
        ], UploadLimit::messages('file'));
        $assessment = Assessment::query()->where('movement_id', $movement->id)->findOrFail($data['assessment_id']);
        $file = $data['kind'] === 'file' ? $request->file('file') : null;

        $evidence = $upload->handle(
            $request->user(),
            $assessment,
            $file !== null ? (string) $file->getRealPath() : null,
            $file?->getClientOriginalName(),
            $data['title'] ?? null,
            isset($data['category']) ? Category::query()->where('code', $data['category'])->firstOrFail() : null,
            array_values($data['questions'] ?? []),
            // Typed as on the ODP: "5.1, 5.2" or "5.1 5.2".
            array_values(array_filter(preg_split('/[\s,;]+/', (string) ($data['tasks'] ?? '')) ?: [])),
            (bool) ($data['share'] ?? false),
            $data['kind'] === 'link' ? ($data['url'] ?? null) : null,
        );

        return redirect()->route('evidence.index', ['movement' => $movement, 'assessment' => $assessment->id])
            ->with('status', "“{$evidence->title}” added to the evidence.");
    }

    /** The file, or for a link, the page it points to. */
    public function download(Request $request, Evidence $evidence): StreamedResponse|RedirectResponse
    {
        Gate::authorize('download', $evidence);
        Audit::record($request->user(), 'file.downloaded', $evidence, $evidence->assessment, payload: ['file' => $evidence->isLink() ? (string) $evidence->url : $evidence->original_name]);

        return $evidence->isLink()
            ? redirect()->away((string) $evidence->url)
            : Storage::disk((string) $evidence->disk)->download((string) $evidence->path, $evidence->original_name);
    }

    public function share(Request $request, Evidence $evidence, ShareEvidence $share): RedirectResponse
    {
        $shared = $request->boolean('shared');
        $share->handle($request->user(), $evidence, $shared);

        return back()->with('status', $shared
            ? "“{$evidence->title}” is shared with the other movements."
            : "“{$evidence->title}” is no longer shared.");
    }

    public function move(Request $request, Evidence $evidence, MoveEvidence $move): RedirectResponse
    {
        // A category's code, or "general".
        $data = $request->validate(['category' => ['required', 'string', 'in:general,'.Category::query()->pluck('code')->implode(',')]]);
        $to = $data['category'] !== 'general' ? Category::query()->where('code', $data['category'])->firstOrFail() : null;

        $takenOff = $move->handle($request->user(), $evidence, $to);

        return back()->with('status', "“{$evidence->title}” moved to ".($to !== null ? $to->name : 'General').'.'
            .($takenOff !== [] ? ' Links that belong to another category were taken off: '.implode(', ', $takenOff).'.' : ''));
    }

    public function destroy(Request $request, Evidence $evidence, RemoveEvidence $remove): RedirectResponse
    {
        $remove->handle($request->user(), $evidence);

        return back()->with('status', "“{$evidence->title}” removed from the evidence.");
    }
}
