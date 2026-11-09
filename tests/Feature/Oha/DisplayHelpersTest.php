<?php

use App\Enums\ArtefactKind;
use App\Enums\BandCode;
use App\Enums\FindingSeverity;
use App\Enums\HolderRole;
use App\Enums\Stage;
use App\Models\WorkItem;
use App\Support\Viz;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/*
 * The one source for how figures, dates, bands, findings and stages are shown: the
 * helpers every page and document uses instead of writing them out.
 */

it('writes figures as people do, with or without thousands separators', function () {
    expect(Viz::figure(12.5))->toBe('12.5')
        ->and(Viz::figure(12.0))->toBe('12')
        ->and(Viz::figure(58.25))->toBe('58.25')
        ->and(Viz::figure(1250.5))->toBe('1,250.5')
        ->and(Viz::figure(1250.5, thousands: ''))->toBe('1250.5')
        ->and(Viz::figure(100))->toBe('100')
        ->and(Viz::figure(19.96, 1))->toBe('20')
        ->and(Viz::figure(null))->toBe('')
        ->and(Viz::points(58, 84))->toBe('58 / 84')
        ->and(Viz::points(58.5, 84, '/'))->toBe('58.5/84')
        ->and(Viz::points(null, 84))->toBe('—')
        ->and(Viz::pct(69))->toBe('69.0%')
        ->and(Viz::pct(null))->toBe('—');
});

it('writes the platform’s three date formats', function () {
    $date = Carbon::parse('2026-11-09 14:05');

    expect($date->asDay())->toBe('9 Nov 2026')
        ->and($date->asStamp())->toBe('9 Nov 2026, 14:05')
        ->and($date->asMonth())->toBe('Nov 2026')
        ->and(CarbonImmutable::parse('2027-01-02')->asDay())->toBe('2 Jan 2027');
});

it('gives each band one tone and ring colour', function () {
    expect(BandCode::toneOf('excellent'))->toBe('good')
        ->and(BandCode::toneOf('strong'))->toBe('good')
        ->and(BandCode::toneOf('developing'))->toBe('warning')
        ->and(BandCode::toneOf('atrisk'))->toBe('serious')
        ->and(BandCode::toneOf('critical'))->toBe('critical')
        ->and(BandCode::toneOf('notstarted'))->toBe('neutral')
        ->and(BandCode::toneOf(null))->toBe('neutral')
        ->and(BandCode::AtRisk->stroke())->toBe('text-band-atrisk')
        ->and(Viz::bandStroke('unknown'))->toBe('text-band-notstarted');
});

it('orders and labels findings by how serious they are', function () {
    $sorted = collect([FindingSeverity::Warning, FindingSeverity::Error, FindingSeverity::Missing])->sortBy(fn ($s) => $s->rank())->values();

    expect($sorted->all())->toBe([FindingSeverity::Error, FindingSeverity::Missing, FindingSeverity::Warning])
        ->and(FindingSeverity::Error->tone())->toBe('critical')
        ->and(FindingSeverity::Missing->icon())->toBe('playlist_remove')
        ->and(FindingSeverity::Warning->label())->toBe(__('oha.severity.warning'));
});

it('works out a movement’s stage from its work', function () {
    $work = fn (string $holder, string $kind) => (new WorkItem)->forceFill(['holder_role' => $holder, 'artefact_kind' => $kind]);

    expect(Stage::of(null))->toBe(Stage::NotAssessed)
        ->and(Stage::ofWork(null))->toBe(Stage::Complete)
        ->and(Stage::ofWork($work(HolderRole::Assessor->value, ArtefactKind::Report->value)))->toBe(Stage::Report)
        ->and(Stage::ofWork($work(HolderRole::Approver->value, ArtefactKind::Form->value)))->toBe(Stage::Form)
        ->and(Stage::ofWork($work(HolderRole::Board->value, ArtefactKind::Odp->value)))->toBe(Stage::Sign)
        ->and(Stage::Complete->label())->toBe('Stage 1 done · follow-ups ongoing')
        ->and(Stage::Complete->short())->toBe('Stage 1 done');
});
