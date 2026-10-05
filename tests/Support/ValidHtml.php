<?php

namespace Tests\Support;

use DOMDocument;
use PHPUnit\Framework\Assert;

/**
 * Checks a page for broken markup: mismatched or unclosed tags. HTML5 elements
 * the old libxml parser does not know (main, nav, svg…) are not errors.
 */
final class ValidHtml
{
    private const UNKNOWN_TAG = 801;

    public static function assert(string $html, string $page): void
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        (new DOMDocument)->loadHTML($html, LIBXML_NOWARNING);

        $problems = collect(libxml_get_errors())
            ->reject(fn ($e) => $e->code === self::UNKNOWN_TAG)
            ->map(fn ($e) => 'line '.$e->line.': '.trim($e->message))
            ->values()->all();

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        Assert::assertSame([], $problems, "Broken markup on {$page}");
    }
}
