<?php

use App\Oha\Interpreter;
use App\Oha\Report\ReadReport;
use App\Oha\Report\ReportChecker;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Journey;

/*
 * A form or a report written in French is read and understood as one in English; what
 * the platform stores and writes stays in English.
 */

it('reads a French answer as the form’s own option or figure', function (string $code, string $answer, mixed $expected) {
    expect(Interpreter::read(Interpreter::question($code), $answer))->toBe($expected);
})->with([
    'oui' => ['Q106', 'Oui', ['value' => 'Yes']],
    'non, with more words' => ['Q106', 'Non, pas encore', ['value' => 'No']],
    'sans objet' => ['Q203', 'Sans objet', ['value' => 'Not Applicable']],
    'single entity' => ['Q112', 'Entité unique', ['value' => 'Single Entity']],
    'federation' => ['Q112', 'Structure fédérative', ['value' => 'Federative Structure']],
    'board' => ['Q310', 'Conseil d’administration', ['value' => 'Board of Directors']],
    'general assembly' => ['Q310', 'Assemblée générale', ['value' => 'General Assembly']],
    'more than five years' => ['Q206', 'Plus de 5 ans', ['value' => 'More than 5 years']],
    'one to five years' => ['Q206', '1 à 5 ans', ['value' => '1 - 5 years']],
    'less than a year' => ['Q206', 'Moins d’un an', ['value' => 'Less than 1 year']],
    'twice or more' => ['Q326#2', '2 fois ou plus', ['value' => '2 or more times']],
    'none' => ['Q326#2', 'Aucune', ['value' => 'None']],
    'three times' => ['Q326#2', 'trois fois', ['value' => '2 or more times']],
    'months' => ['Q241', '12 mois', ['value' => 12]],
    'years, not months' => ['Q241', '2 ans', ['unit' => 'years', 'expected' => 'the number of months']],
    'about, with a noun' => ['Q1013', 'environ 300 bénévoles', ['value' => 300]],
    'a comparison is not guessed' => ['Q1013', 'plus de 50', []],
    'in words' => ['Q1013', 'vingt et un', ['value' => 21]],
    'hundreds' => ['Q1013', 'deux cents', ['value' => 200]],
    'millions, with a decimal comma' => ['Q211', '2,5 millions', ['value' => 2500000]],
    'a percentage with a decimal comma' => ['Q224', '45,5 %', ['value' => 45.5]],
    'spaces between thousands' => ['Q211', '1 234 567,89', ['value' => 1234567.89]],
]);

it('reads a report written in French: its sections, categories, overall score and author', function () {
    Notification::fake();
    $j = Journey::begin();
    $assessment = $j->assessmentAt('form_uploaded');

    $filler = str_repeat('La YMCA mène ses programmes avec les jeunes et les communautés, avec le soutien de ses bénévoles et de ses partenaires. ', 8);
    $p = fn (string $text, ?int $heading = null, bool $list = false) => ['text' => $text, 'heading' => $heading, 'bold' => $heading !== null, 'list' => $list];
    $categories = [
        '1. Stabilité financière' => 'La YMCA a obtenu 60 % dans cette catégorie.',
        '2. Gouvernance' => 'Le conseil se réunit régulièrement.',
        '3. Statuts, règlement intérieur, politiques et procédures' => 'Les statuts sont à jour.',
        '4. Suivi et évaluation' => 'Le suivi des projets est en place.',
        '5. Planification stratégique et mission' => 'Le plan stratégique est en place.',
        '6. Diversité et participation des jeunes' => 'Les jeunes siègent au conseil.',
        '7. Communication et image de marque' => 'Le site web est à jour.',
        '8. Gestion des biens' => 'Les biens sont enregistrés.',
        '9. Développement du personnel et des bénévoles' => 'Le personnel est recruté officiellement.',
    ];
    $paragraphs = [
        $p('RAPPORT D’ÉVALUATION DE LA SANTÉ ORGANISATIONNELLE – Zambia YMCA, février 2026', 1),
        $p('Rapport rédigé par : Tendai Moyo, AAYMCA'),
        $p('Aperçu de l’organisation', 2),
        $p('La YMCA de Zambie est une association enregistrée. '.$filler),
        $p('État de santé global', 2),
        $p('La YMCA a obtenu un score global de 69,05 % (58/84), ce qui la place dans la catégorie « en développement ».'),
        $p('Points forts', 3),
        $p('Une gouvernance solide.', list: true),
        $p('Risques', 3),
        $p('Une dépendance à une seule source de revenus.', list: true),
        $p('Axes d’amélioration', 3),
        $p('Diversifier les revenus.', list: true),
    ];
    foreach ($categories as $title => $text) {
        array_push($paragraphs, $p($title, 3), $p($text), $p('Recommandations :'), $p('Agir dans cette catégorie.', list: true));
    }

    $check = app(ReportChecker::class)->check(new ReadReport('docx', $paragraphs), $assessment);

    expect(collect($check['findings'])->pluck('message')->all())->toBe([])
        ->and($check['stated'])->toBe(['pct' => 69.05, 'points' => 58.0, 'out_of' => 84.0]);
});

it('still takes "global" in an English report for what it is, not the overall score', function () {
    $report = new ReadReport('docx', [['text' => 'Its global funding share is 40% of income. The overall score is 69.05%.', 'heading' => null, 'bold' => false, 'list' => false]]);

    expect(app(ReportChecker::class)->statedScore($report)['pct'])->toBe(69.05);
});
