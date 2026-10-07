<?php

namespace Tests\Support;

use App\Actions\Oha\ApproveArtefact;
use App\Actions\Oha\OpenAssessment;
use App\Actions\Oha\SignAsBoard;
use App\Actions\Oha\SubmitForApproval;
use App\Actions\Oha\UploadForm;
use App\Actions\Oha\UploadOdpVersion;
use App\Actions\Oha\UploadReportVersion;
use App\Models\Artefact;
use App\Models\Assessment;
use App\Models\BoardSignature;
use App\Models\Document;
use App\Models\Movement;
use App\Models\User;
use Database\Seeders\MovementsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * The people of a Stage 1 walkthrough on Zambia, and a way to take an
 * assessment to any stage through the real actions.
 */
final class Journey
{
    /** A tiny valid PNG, standing in for a drawn signature. */
    /** What the Chairperson types to sign: their initials. */
    public const SIGNATURE = 'N. M.';

    public const STAGES = [
        'opened', 'form_uploaded', 'form_submitted', 'form_approved',
        'report_uploaded', 'report_submitted', 'report_approved',
        'odp_uploaded', 'odp_submitted', 'odp_approved', 'odp_signed',
    ];

    /** A Google Docs link, as copied from the address bar. */
    public const DRIVE_URL = 'https://docs.google.com/document/d/1ZaMbIaOdP2026xYzAbCdEfGhIjKlMnOpQr/edit?usp=sharing';

    public Movement $zambia;

    public Movement $ghana;

    public User $assessor;

    public User $otherStaff;

    /** An Administrator: approves, and is an assessor too when assigned. */
    public User $admin;

    public User $secondAdmin;

    public User $superAdmin;

    public User $chair;

    public User $ghanaChair;

    public static function begin(): self
    {
        Storage::fake('oha');
        test()->seed(MovementsSeeder::class);

        $j = new self;
        $j->zambia = Movement::query()->where('slug', 'zambia')->firstOrFail();
        $j->ghana = Movement::query()->where('slug', 'ghana')->firstOrFail();
        $j->assessor = User::factory()->create(['name' => 'Tendai Moyo']);
        $j->assessor->assignedMovements()->attach($j->zambia);
        $j->otherStaff = User::factory()->create(['name' => 'Aminata Diallo']);
        $j->admin = User::factory()->admin()->create(['name' => 'Gloria Anyika']);
        $j->secondAdmin = User::factory()->admin()->create(['name' => 'Yirga Tesfaye']);
        $j->superAdmin = User::factory()->superAdmin()->create(['name' => 'Achieng Odhiambo']);
        $j->chair = User::factory()->chair($j->zambia)->create(['name' => 'Naledi Moyo', 'title' => 'Board Chairperson']);
        $j->ghanaChair = User::factory()->chair($j->ghana)->create();

        return $j;
    }

    /** An assessment for Zambia, taken through every step up to and including $stage. */
    public function assessmentAt(string $stage): Assessment
    {
        $target = array_search($stage, self::STAGES, true);
        $assessment = app(OpenAssessment::class)->handle($this->assessor, $this->zambia, 'Feb 2026', Carbon::parse('2026-02-01'));

        $steps = [
            'form_uploaded' => fn () => app(UploadForm::class)->handle($this->form($assessment), zambiaFormPath(), 'ZAM26 OHA Form.xlsx', $this->assessor),
            'form_submitted' => fn () => app(SubmitForApproval::class)->handle($this->form($assessment), $this->assessor, acknowledgeGaps: true, reason: 'Following up with the movement.'),
            'form_approved' => fn () => app(ApproveArtefact::class)->handle($this->form($assessment), $this->admin),
            'report_uploaded' => fn () => $this->uploadReport($assessment, 'Zambia OHA report, first version'),
            'report_submitted' => fn () => app(SubmitForApproval::class)->handle($this->report($assessment), $this->assessor),
            'report_approved' => fn () => app(ApproveArtefact::class)->handle($this->report($assessment), $this->admin),
            'odp_uploaded' => fn () => $this->uploadOdp($assessment, 'Zambia ODP, first version'),
            'odp_submitted' => fn () => app(SubmitForApproval::class)->handle($this->odp($assessment), $this->assessor),
            'odp_approved' => fn () => app(ApproveArtefact::class)->handle($this->odp($assessment), $this->admin),
            'odp_signed' => fn () => $this->sign($this->odp($assessment)),
        ];

        foreach (array_slice(self::STAGES, 1, (int) $target) as $step) {
            $steps[$step]();
        }

        return $assessment;
    }

    /** Saves a version of the report; different $content makes a different file. */
    public function uploadReport(Assessment $assessment, string $content, ?User $by = null, string $name = 'Zambia OHA Report 2026.pdf', ?string $note = null): Document
    {
        return app(UploadReportVersion::class)->handle($this->report($assessment), self::reportFile($content), $name, $by ?? $this->assessor, $note);
    }

    /**
     * Saves a Word report built from lines (see WordDocument::make).
     *
     * @param  list<string>  $lines
     */
    public function uploadWordReport(Assessment $assessment, array $lines, bool $picture = false, ?User $by = null): Document
    {
        return app(UploadReportVersion::class)->handle($this->report($assessment), WordDocument::make($lines, $picture), 'Zambia OHA Report 2026.docx', $by ?? $this->assessor);
    }

    /** Saves a version of the ODP, with the link to its Google Drive document. */
    public function uploadOdp(Assessment $assessment, string $content, ?User $by = null, string $name = 'Zambia ODP 2026.pdf', ?string $note = null, ?string $driveUrl = self::DRIVE_URL): Document
    {
        return app(UploadOdpVersion::class)->handle($this->odp($assessment), self::reportFile($content), $name, $by ?? $this->assessor, $note, $driveUrl);
    }

    /** A small stand-in PDF on disk, whose bytes depend on $content. */
    public static function reportFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rpt');
        file_put_contents($path, "%PDF-1.4\n% {$content}\n%%EOF\n");

        return $path;
    }

    public function sign(Artefact $artefact, ?User $by = null): BoardSignature
    {
        return app(SignAsBoard::class)->handle($artefact, $by ?? $this->chair, self::SIGNATURE, confirmed: true);
    }

    public function form(Assessment $assessment): Artefact
    {
        return $assessment->form()->firstOrFail();
    }

    public function report(Assessment $assessment): Artefact
    {
        return $assessment->report()->firstOrFail();
    }

    public function odp(Assessment $assessment): Artefact
    {
        return $assessment->odp()->firstOrFail();
    }
}
