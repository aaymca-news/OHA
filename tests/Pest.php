<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Path to a real movement's 2026 OHA form (or the OW2026 blank template) in
 * "O.H.A Excel Forms". They hold real data and live outside version control, so
 * tests that need one are skipped without it.
 */
function ohaFormPath(string $prefix): string
{
    $matches = glob(base_path('../O.H.A Excel Forms/'.$prefix.'*.xlsx')) ?: [];

    if ($matches === []) {
        test()->markTestSkipped("The {$prefix} OHA form is not available in O.H.A Excel Forms.");
    }

    return $matches[0];
}

/**
 * Path to the real Zambia YMCA 2026 OHA form. It holds a real movement's data and
 * lives outside version control, so tests that need it are skipped without it.
 */
function zambiaFormPath(): string
{
    $path = (string) config('oha.zambia.form');

    if (! is_file($path)) {
        test()->markTestSkipped('The Zambia 2026 OHA form is not available at '.$path);
    }

    return $path;
}
