<?php

namespace Database\Seeders;

use App\Actions\Oha\OpenAssessment;
use App\Actions\Oha\UploadForm;
use App\Actions\Oha\UploadOdpVersion;
use App\Actions\Oha\UploadReportVersion;
use App\Models\Assessment;
use App\Models\Movement;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * LOCAL ONLY: the real assessments of 11 National Movements, for testing. Each
 * movement's form, report and ODP go in through the same actions as the screens,
 * uploaded by that movement's assessor. Nothing is approved or signed, and the
 * form's gaps are left open to be resolved on the site.
 *
 * Run with: php artisan db:seed --class=NationalMovementsSeeder
 *
 * The files hold real movement data and live outside version control, in
 * config('oha.national_movements'). A movement that already has an assessment is
 * skipped, so running it again only adds what is missing.
 */
class NationalMovementsSeeder extends Seeder
{
    /** Folder => movement slug and the assessment month, as each report gives it. */
    private const MOVEMENTS = [
        'ETHIOPIA' => ['ethiopia', '2025-07'],
        'GHANA' => ['ghana', '2025-07'],
        'LIBERIA' => ['liberia', '2025-11'],
        'MADAGASCAR' => ['madagascar', '2025-07'],
        'NIGERIA' => ['nigeria', '2026-04'],
        'SIERRA LEONE' => ['sierra-leone', '2025-11'],
        'SOUTH AFRICA' => ['south-africa', '2025-08'],
        'SOUTH SUDAN' => ['south-sudan', '2026-03'],
        'TOGO' => ['togo', '2025-07'],
        'ZAMBIA' => ['zambia', '2026-02'],
        'Zimbabwe' => ['zimbabwe', '2026-02'],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('NationalMovementsSeeder loads test data and must not run in production.');
        }

        $root = (string) config('oha.national_movements');
        if (! is_dir($root)) {
            throw new RuntimeException("The National Movements folder was not found at {$root}.");
        }

        foreach (self::MOVEMENTS as $folder => [$slug, $month]) {
            $movement = Movement::query()->where('slug', $slug)->firstOrFail();
            if (Assessment::query()->where('movement_id', $movement->id)->exists()) {
                $this->command->line("  {$movement->name}: already has an assessment, skipped.");

                continue;
            }

            $files = $this->files("{$root}/{$folder}");
            $assessor = $movement->assessors()->orderBy('name')->get()->first(fn ($user) => $user->canAssess($movement));
            if ($assessor === null) {
                throw new RuntimeException("No assessor is assigned to {$movement->name}.");
            }

            // "!" starts the date at the 1st: without it, today's day is used, and on the 29th "2026-02" overflows into March.
            $start = Carbon::createFromFormat('!Y-m', $month);
            $assessment = app(OpenAssessment::class)->handle($assessor, $movement, $start->format('M Y'), $start);

            app(UploadForm::class)->handle($assessment->form()->firstOrFail(), $files['form'], basename($files['form']), $assessor);
            app(UploadReportVersion::class)->handle($assessment->report()->firstOrFail(), $files['report'], basename($files['report']), $assessor);
            // Uploading the ODP needs its Google Drive link; this stand-in is replaced on the site.
            app(UploadOdpVersion::class)->handle($assessment->odp()->firstOrFail(), $files['odp'], basename($files['odp']), $assessor,
                driveUrl: 'https://docs.google.com/spreadsheets/d/LOCAL-TEST-ONLY-ODP-'.$slug.'/edit');

            $this->command->line("  {$movement->name}: {$assessment->period_label}, uploaded by {$assessor->name}.");
        }
    }

    /**
     * The movement's three files: the form and the ODP are workbooks, the report a Word document.
     *
     * @return array{form: string, report: string, odp: string}
     */
    private function files(string $dir): array
    {
        $workbooks = glob($dir.'/*.xlsx') ?: [];
        $form = collect($workbooks)->first(fn ($f) => preg_match('/assessment\s*form/i', basename($f)) === 1);
        $odp = collect($workbooks)->first(fn ($f) => $f !== $form);
        $report = (glob($dir.'/*.docx') ?: [])[0] ?? null;

        if ($form === null || $odp === null || $report === null) {
            throw new RuntimeException("{$dir} needs the OHA form, the ODP (both .xlsx) and the report (.docx).");
        }

        return ['form' => $form, 'report' => $report, 'odp' => $odp];
    }
}
