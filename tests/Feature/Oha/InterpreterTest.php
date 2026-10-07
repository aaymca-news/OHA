<?php

use App\Oha\Interpreter;

/*
 * Answers written in words are read as the number or option they plainly mean, and
 * that is what is stored. Nothing ambiguous is guessed, and a figure in the wrong unit
 * is flagged, never converted.
 */

function readAs(string $code, mixed $raw, ?string $currency = null): array
{
    return Interpreter::read(Interpreter::question($code), $raw, $currency);
}

it('reads counts written with their noun, in words, or with separators', function (string $code, mixed $raw, int|float $value) {
    expect(readAs($code, $raw))->toBe(['value' => $value]);
})->with([
    'with what is counted' => ['Q1013', '385 volunteers', 385],
    'in words' => ['Q315', 'twelve', 12],
    'hyphenated words' => ['Q518', 'twenty-five', 25],
    'words with hundred' => ['Q518', 'one hundred and five', 105],
    'thousands separator' => ['Q518', '18,155', 18155],
    'spaced thousands' => ['Q518', '18 155 people', 18155],
    'approximately' => ['Q1008', 'about 90', 90],
    'none' => ['Q317', 'None', 0],
    'nil' => ['Q1010', 'nil', 0],
    'months asked, months given' => ['Q241', '8 months', 8],
    'years asked, years given' => ['Q322', '4 years', 4],
    'percentage with its sign' => ['Q214', '25%', 25],
    'percentage in words' => ['Q229', '73.5 percent', 73.5],
]);

it('reads Yes, No and the other options however they are written', function (string $code, string $raw, string $value) {
    expect(readAs($code, $raw))->toBe(['value' => $value]);
})->with([
    'Y' => ['Q201', 'Y', 'Yes'],
    'yes with a full stop' => ['Q201', 'yes.', 'Yes'],
    'yes with more words' => ['Q201', 'Yes, we have one', 'Yes'],
    'no with more words' => ['Q227', 'No - not yet', 'No'],
    'N/A' => ['Q203', 'N/A', 'Not Applicable'],
    'spacing in an option' => ['Q206', '1-5 years', '1 - 5 years'],
    'years as a range' => ['Q206', '3 years', '1 - 5 years'],
    'months as a range' => ['Q206', '8 months', 'Less than 1 year'],
    'times as a number' => ['Q326#2', '2', '2 or more times'],
    'once' => ['Q326#2', 'once', '1 time'],
    'the start of an option' => ['Q112', 'Single entity YMCA', 'Single Entity'],
    'a word of an option' => ['Q310', 'Board of directors', 'Board of Directors'],
]);

it('reads money with its currency, and in thousands or millions', function (string $raw, int|float $value) {
    expect(readAs('Q211', $raw, 'USD'))->toBe(['value' => $value]);
})->with([
    ['USD 528,359.77', 528359.77],
    ['USD, 87,584.12', 87584.12],
    ['USD 1.2 million', 1200000],
    ['90 thousand', 90000],
    ['$ 4,500', 4500],
]);

it('flags a figure in the wrong unit instead of converting it', function (string $code, string $raw, string $unit, ?string $currency = null) {
    $result = readAs($code, $raw, $currency);

    expect($result)->not->toHaveKey('value')
        ->and($result['unit'])->toContain($unit);
})->with([
    'years where months are asked' => ['Q241', '2 years', 'years'],
    'weeks where months are asked' => ['Q241', '10 weeks', 'weeks'],
    'months where years are asked' => ['Q324', '36 months', 'months'],
    'a weight for a head count' => ['Q1008', '40 kg', 'weight'],
    'a volume for a count' => ['Q118', '20 litres', 'volume'],
    'money for a head count' => ['Q1008', 'USD 400', 'money'],
    'a percentage for a head count' => ['Q315', '45%', 'percentage'],
    'money in a percentage' => ['Q214', 'USD 300', 'money'],
    'another currency than Q208' => ['Q209', 'ZAR 418 332.92', 'ZAR', 'USD'],
    'a weight for money' => ['Q211', '300 kg', 'weight', 'USD'],
]);

it('never guesses at answers that are not one plain figure or option', function (string $code, string $raw) {
    expect(readAs($code, $raw))->toBe([]);
})->with([
    'two numbers' => ['Q118', '54 branches, 30 active'],
    'a sum' => ['Q314', 'max is 33 plus 6 regional directors'],
    'no figure' => ['Q313', 'No Minimum'],
    'a comparison' => ['Q1013', 'more than 300'],
    'a range' => ['Q1013', '300-400'],
    'plus' => ['Q1013', '300+'],
    'an answer outside the options' => ['Q310', 'Council'],
]);
