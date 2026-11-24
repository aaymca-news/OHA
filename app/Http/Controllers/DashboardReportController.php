<?php

namespace App\Http\Controllers;

use App\Models\Movement;
use App\Models\MovementStatus;
use App\Models\Zone;
use App\Oha\Report\DashboardPdf;
use App\Oha\Report\DashboardWorkbook;
use App\Queries\DashboardAnalytics;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The dashboards to download (decided 23 Nov 2026): as a PDF to read and share, or as an
 * Excel workbook to work the figures further. The same figures as the screen, to the same
 * people: AAYMCA's for its staff (the region, or one zone), a movement's for its own users
 * and AAYMCA staff.
 */
class DashboardReportController extends Controller
{
    /** AAYMCA's dashboard, for the region or one zone (?zone=). */
    public function region(Request $request, string $format, DashboardAnalytics $analytics, DashboardPdf $pdf, DashboardWorkbook $workbook): Response
    {
        Gate::authorize('viewAllianceStats');
        $zone = $request->filled('zone') ? Zone::query()->findOrFail($request->integer('zone')) : null;
        $data = $analytics->region($zone);
        abort_if($data['figures']['assessed'] === 0, 404);

        $content = $format === 'pdf' ? $pdf->region($zone, $data) : $workbook->region($zone, $data);
        Audit::record($request->user(), 'dashboard.downloaded', $request->user(), payload: ['format' => $format, 'zone' => $zone?->name]);

        return $this->download($content, $format, 'AAYMCA - OHA dashboard - '.($zone->name ?? 'All zones'));
    }

    /** A movement's dashboard. */
    public function movement(Request $request, Movement $movement, string $format, DashboardAnalytics $analytics, DashboardPdf $pdf, DashboardWorkbook $workbook): Response
    {
        Gate::authorize('view', $movement);
        $data = $analytics->movement(MovementStatus::query()->with(['band', 'movement'])->findOrFail($movement->id));
        abort_unless($data['rated'], 404);

        $content = $format === 'pdf' ? $pdf->movement($movement, $data) : $workbook->movement($movement, $data);
        Audit::record($request->user(), 'dashboard.downloaded', $movement, payload: ['format' => $format]);

        return $this->download($content, $format, $movement->name.' - OHA dashboard');
    }

    /** "Zambia YMCA - OHA dashboard - 23 Nov 2026.pdf", safe as a file name. */
    private function download(string $content, string $format, string $name): Response
    {
        $file = trim((string) preg_replace('#[\\\\/:*?"<>|\x00-\x1F]+#', '-', $name.' - '.now()->format('j M Y'))).'.'.$format;

        return response($content, 200, [
            'Content-Type' => $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$file.'"',
        ]);
    }
}
