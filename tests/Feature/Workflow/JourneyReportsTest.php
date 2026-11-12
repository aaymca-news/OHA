<?php

use App\Actions\Oha\Rescore;
use App\Models\User;
use App\Oha\Rescoring;
use App\Oha\ScoreGaps;
use App\Queries\Journeys;
use Illuminate\Support\Facades\Notification;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Smalot\PdfParser\Parser;
use Tests\Support\Journey;

/*
 * The cycle's journey to download (decided 12 Nov 2026): a PDF with the growth curve, rings
 * and what changed; an Excel workbook of the same figures; and the baseline columns in the
 * Alliance's scores CSV.
 */

beforeEach(function () {
    Notification::fake();
    $this->j = Journey::begin();
    $this->ngs = User::factory()->generalSecretary($this->j->zambia)->create();
    $this->assessment = $this->j->assessmentAt('closed');

    $this->j->openFollowUp($this->assessment);
    $this->gained = collect(ScoreGaps::for(Rescoring::inForce($this->assessment, 1)->answers))
        ->flatten(1)->first(fn (array $i) => ($i['options'] ?? []) !== [] && $i['points'] === 0 && $i['max'] === 1);
    app(Rescore::class)->change($this->assessment, $this->j->assessor, 'q:'.$this->gained['code'], $this->gained['options'][0], 'The board approved it in March.');
    $this->j->holdFollowUp($this->assessment, 1);
    $this->steps = app(Journeys::class)->of($this->assessment);
});

it('downloads the journey as a PDF that reads back: figures, curve, rings and what changed', function () {
    $response = $this->actingAs($this->ngs)->get(route('baselines.pdf', $this->assessment))->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
    expect($response->headers->get('Content-Disposition'))->toContain('Zambia YMCA - OHA cycle journey - Feb 2026.pdf');

    $content = $response->getContent();
    $document = (new Parser)->parseContent($content);
    $text = $document->getText();

    expect($content)->toStartWith('%PDF-1.4')->toEndWith("%%EOF\n")
        ->and(count($document->getPages()))->toBeGreaterThanOrEqual(3)
        ->and($text)->toContain('Zambia YMCA', 'Growth curve', 'Where it stands now', 'ODP tasks now', 'Each category, baseline by baseline',
            'What changed at each follow-up', 'The board approved it in March.', number_format($this->steps[1]['pct'], 1).'%');
});

it('downloads the journey as an Excel workbook with the same figures', function () {
    $content = $this->actingAs($this->j->assessor)->get(route('baselines.workbook', $this->assessment))->assertOk()->getContent();
    $path = tempnam(sys_get_temp_dir(), 'wb').'.xlsx';
    file_put_contents($path, $content);
    $book = IOFactory::load($path);

    expect($book->getSheetNames())->toBe(['Baselines', 'Categories', 'Changes', 'Evidence gaps'])
        ->and($book->getSheetByName('Baselines')->getHighestRow())->toBe(3)
        ->and((float) $book->getSheetByName('Baselines')->getCell('G3')->getValue())->toBe($this->steps[1]['pct'])
        ->and($book->getSheetByName('Changes')->getCell('B2')->getValue())->toBe(str_replace('#2', ' (2nd)', $this->gained['code']))
        ->and($book->getSheetByName('Changes')->getCell('F2')->getValue())->toBe('The board approved it in March.');
    $book->disconnectWorksheets();
    @unlink($path);
});

it('adds the baseline and the change since Baseline 1 to the scores CSV, and keeps the journey from the Board Chairperson', function () {
    $csv = $this->actingAs($this->j->otherStaff)->get(route('downloads.scores'))->assertOk()->getContent();
    $rows = array_map(fn ($line) => str_getcsv($line, escape: ''), array_filter(explode("\n", trim(ltrim($csv, "\xEF\xBB\xBF")))));
    $head = $rows[0];
    $zambia = collect($rows)->first(fn ($r) => $r[0] === 'Zambia YMCA');

    expect(array_slice($head, -3))->toBe(['Baseline', 'Baseline 1 (%)', 'Change since Baseline 1 (pts)'])
        ->and(array_slice($zambia, -3))->toBe(['2', (string) $this->steps[0]['pct'], (string) round($this->steps[1]['pct'] - $this->steps[0]['pct'], 1)]);

    $this->actingAs($this->j->chair)->get(route('baselines.pdf', $this->assessment))->assertForbidden();
    $this->actingAs($this->j->chair)->get(route('baselines.workbook', $this->assessment))->assertForbidden();
});
