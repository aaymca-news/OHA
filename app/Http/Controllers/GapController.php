<?php

namespace App\Http\Controllers;

use App\Actions\Oha\ResolveGap;
use App\Models\FormFinding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GapController extends Controller
{
    public function resolve(Request $request, FormFinding $finding, ResolveGap $gaps): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:2000']]);
        $gaps->resolve($finding, $request->user(), $data['note']);

        return $this->back($finding, 'Marked as resolved.');
    }

    public function reopen(Request $request, FormFinding $finding, ResolveGap $gaps): RedirectResponse
    {
        $gaps->reopen($finding, $request->user());

        return $this->back($finding, 'Reopened.');
    }

    private function back(FormFinding $finding, string $status): RedirectResponse
    {
        return redirect()->route('assessments.show', ['assessment' => $finding->formUpload->artefact->assessment_id, 'tab' => 'form'])
            ->with('status', $status);
    }
}
