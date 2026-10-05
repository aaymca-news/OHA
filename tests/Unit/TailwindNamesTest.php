<?php

/*
 * The design tokens define spacing named xs, sm, md, lg, xl and gutter (gap-md,
 * p-lg…). In Tailwind v4 the width utilities read the spacing scale too, so
 * max-w-md would become 16px instead of 28rem and squeeze the page. Widths must
 * use explicit values such as max-w-[28rem].
 */
it('never uses a width class that collides with the spacing names', function () {
    $offenders = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/resources/views'));
    foreach ($files as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')
            && preg_match_all('/\b(?:max-w|min-w|w|basis|max-h|min-h|h|size)-(?:xs|sm|md|lg|xl|gutter)\b/', (string) file_get_contents($file->getPathname()), $m)) {
            $offenders[] = $file->getFilename().': '.implode(', ', array_unique($m[0]));
        }
    }

    expect($offenders)->toBe([]);
});
