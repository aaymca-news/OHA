<?php

namespace App\Http\Controllers;

use App\Enums\DocumentFormat;
use App\Models\BoardSignature;
use App\Models\Document;
use App\Models\FormUpload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hands out stored OHA files, under the name they were uploaded with. Who may
 * download what is decided by the policies, on the routes.
 */
class DownloadController extends Controller
{
    /** The OHA form as corrected in the platform; with ?original=1, the file exactly as uploaded. */
    public function form(Request $request, FormUpload $formUpload): StreamedResponse
    {
        $file = $request->boolean('original')
            ? ['disk' => $formUpload->disk, 'path' => $formUpload->path, 'name' => $formUpload->original_name]
            : $formUpload->shownFile();

        return Storage::disk($file['disk'])->download($file['path'], $file['name']);
    }

    public function document(Document $document): StreamedResponse
    {
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

    public function blankForm(): BinaryFileResponse
    {
        return response()->download((string) config('oha.blank_form'), 'YMCA OHA Form 2026 (blank).xlsx');
    }
}
