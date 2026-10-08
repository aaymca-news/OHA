<?php

namespace App\Http\Controllers;

use App\Actions\Oha\DeleteFormUpload;
use App\Actions\Oha\ResolveGap;
use App\Actions\Oha\SupplyAnswer;
use App\Models\FormFinding;
use App\Models\FormUpload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * What the OHA form check found, and correcting it: a note that closes off a gap, an
 * answer typed in the platform, or deleting an upload made by mistake.
 */
class GapController extends Controller
{
    public function resolve(Request $request, FormFinding $finding, ResolveGap $gaps): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:2000']]);
        $gaps->resolve($finding, $request->user(), $data['note']);

        return $this->back($finding->formUpload->artefact->assessment_id, 'Marked as resolved.');
    }

    public function reopen(Request $request, FormFinding $finding, ResolveGap $gaps): RedirectResponse
    {
        $gaps->reopen($finding, $request->user());

        return $this->back($finding->formUpload->artefact->assessment_id, 'Reopened.');
    }

    /**
     * What the form asks for, typed in: one value (an answer, the areas of improvement, a
     * sign-off), several answers by question code, or a percentage per line of a group.
     */
    public function answer(Request $request, FormFinding $finding, SupplyAnswer $supply): RedirectResponse
    {
        $data = $request->validate([
            'value' => ['nullable', 'required_without_all:shares,answers', 'string', 'max:2000'],
            'answers' => ['nullable', 'array'],
            'answers.*' => ['nullable', 'string', 'max:2000'],
            'shares' => ['nullable', 'array'],
            'shares.*' => ['nullable', 'string', 'max:20'],
        ], ['value.required_without_all' => 'Type the answer.']);

        $assessment = $finding->formUpload->artefact->assessment_id;
        $supply->handle($finding, $request->user(), $data['answers'] ?? $data['shares'] ?? (string) $data['value']);

        return $this->back($assessment, 'Answer saved. The form has been checked again with it.');
    }

    public function withdraw(Request $request, FormUpload $formUpload, SupplyAnswer $supply): RedirectResponse
    {
        $data = $request->validate(['ref' => ['required', 'string', 'max:60']]);
        $supply->withdraw($formUpload, $data['ref'], $request->user());

        return $this->back($formUpload->artefact->assessment_id, 'The typed answer is taken back. The form has been checked again without it.');
    }

    public function destroyUpload(Request $request, FormUpload $formUpload, DeleteFormUpload $delete): RedirectResponse
    {
        $assessment = $formUpload->artefact->assessment_id;
        $name = $formUpload->original_name;
        $delete->handle($formUpload, $request->user());

        return $this->back($assessment, "Deleted {$name}.");
    }

    private function back(int $assessment, string $status): RedirectResponse
    {
        return redirect()->route('assessments.show', ['assessment' => $assessment, 'tab' => 'form'])
            ->with('status', $status);
    }
}
