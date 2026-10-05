<?php

/*
 * Readable for people with low vision: text is sized in rem, so it follows the
 * reader's own browser setting and the "Text size" choice, and nothing is smaller
 * than 13px (0.8125rem) at the normal size.
 */

function viewFiles(): array
{
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/resources/views')) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}

it('sizes text in rem, never in pixels', function () {
    $offenders = [];
    foreach (viewFiles() as $path) {
        if (preg_match_all('/\btext-\[\d+(?:\.\d+)?px\]/', (string) file_get_contents($path), $m)) {
            $offenders[] = basename($path).': '.implode(', ', array_unique($m[0]));
        }
    }

    expect($offenders)->toBe([]);
});

it('never sets text smaller than 13px', function () {
    $offenders = [];
    foreach (viewFiles() as $path) {
        $source = (string) file_get_contents($path);
        preg_match_all('/\btext-\[(\d+(?:\.\d+)?)rem\]/', $source, $m);
        foreach ($m[1] as $rem) {
            if ((float) $rem < 0.8125) {
                $offenders[] = basename($path).': text-['.$rem.'rem]';
            }
        }
        if (preg_match('/\btext-(?:xs|2xs)\b/', $source)) {
            $offenders[] = basename($path).': text-xs';
        }
    }

    expect(array_unique($offenders))->toBe([]);
});
