<?php

namespace App\Http\Controllers;

use App\Enums\DocumentFormat;
use App\Models\Assessment;
use App\Models\BoardSignature;
use App\Models\Document;
use App\Models\FormUpload;
use App\Models\Movement;
use App\Support\Audit;
use App\Support\ResourceZip;
use App\Support\ScoresCsv;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hands out stored OHA files, under the name they were uploaded with, singly or as a
 * ZIP. Who may download what is decided by the policies. Each download of a movement's
 * files is recorded in its assessment's audit trail (the download log).
 */
class DownloadController extends Controller
{
    /** The OHA form as corrected in the platform; with ?original=1, the file exactly as uploaded. */
    public function form(Request $request, FormUpload $formUpload): StreamedResponse
    {
        $file = $request->boolean('original')
            ? ['disk' => $formUpload->disk, 'path' => $formUpload->path, 'name' => $formUpload->original_name]
            : $formUpload->shownFile();
        Audit::record($request->user(), 'file.downloaded', $formUpload, $formUpload->artefact->assessment, payload: ['file' => $file['name']]);

        return Storage::disk($file['disk'])->download($file['path'], $file['name']);
    }

    public function document(Request $request, Document $document): StreamedResponse
    {
        Audit::record($request->user(), 'file.downloaded', $document, $document->artefact->assessment, payload: ['file' => $document->original_name]);

        return Storage::disk($document->disk)->download($document->path, $document->original_name);
    }

    /**
     * The same file, shown in the page rather than saved: a PDF opens in the browser's
     * own viewer, and a Word file is drawn on the page by docx-preview.
     */
    public function preview(Document $document): StreamedResponse
    {
        abort_unless($document->canPreview(), 404);

        $type = $document->format === DocumentFormat::Pdf
            ? 'application/pdf'
            : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

        return Storage::disk($document->disk)->response($document->path, $document->original_name, [
            'Content-Type' => $type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ], 'inline');
    }

    /** A drawn board signature (signed before 8 Oct 2026), shown to whoever may see the signed document. */
    public function signature(BoardSignature $signature): StreamedResponse
    {
        Gate::authorize('view', $signature->artefact);
        abort_if($signature->signature_path === null, 404);

        return Storage::disk($signature->signature_disk)->response($signature->signature_path, 'signature.png', ['Content-Type' => 'image/png']);
    }

    /** One assessment's approved documents and evidence, as one ZIP file. */
    public function assessmentZip(Request $request, Assessment $assessment, ResourceZip $zip): BinaryFileResponse
    {
        Gate::authorize('view', $assessment);
        $built = $zip->build($request->user(), collect([$assessment->load('movement')]));

        return response()->download($built['path'], Str::slug($assessment->movement->name.' '.$assessment->period_label).'.zip')->deleteFileAfterSend();
    }

    /** All of a movement's approved documents and evidence, one folder per assessment. */
    public function movementZip(Request $request, Movement $movement, ResourceZip $zip): BinaryFileResponse
    {
        Gate::authorize('view', $movement);
        $assessments = $movement->assessments()->with('movement')->latest('assessed_on')->get()
            ->filter(fn (Assessment $a) => Gate::allows('view', $a))->values();
        $built = $zip->build($request->user(), $assessments);

        return response()->download($built['path'], Str::slug($movement->name).'-resources.zip')->deleteFileAfterSend();
    }

    /** The Alliance's scores as a CSV: each movement's latest, or with ?all=1 every approved assessment. AAYMCA staff only. */
    public function scores(Request $request, ScoresCsv $scores): Response
    {
        abort_unless($request->user()->isSecretariat(), 403, 'Only AAYMCA staff download the Alliance’s scores.');
        $every = $request->boolean('all');
        Audit::record($request->user(), 'scores.exported', $request->user(), payload: ['scope' => $every ? 'every assessment' : 'latest per movement']);

        return response($scores->csv($every), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.($every ? 'alliance-scores-all-assessments' : 'alliance-scores-latest').'-'.now()->format('Y-m-d').'.csv"',
        ]);
    }

    public function blankForm(): BinaryFileResponse
    {
        return response()->download((string) config('oha.blank_form'), 'YMCA OHA Form 2026 (blank).xlsx');
    }

    public function reportTemplate(): BinaryFileResponse
    {
        return response()->download((string) config('oha.report_template'), 'OHA Report Template 2026.docx');
    }

    public function odpTemplate(): BinaryFileResponse
    {
        return response()->download((string) config('oha.odp_template'), 'ODP Template 2026.xlsx');
    }
}
