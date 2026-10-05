<?php

namespace App\Oha;

use App\Enums\FindingSeverity;

/**
 * One thing the check found. Errors refuse the form; gaps ("missing") may be
 * carried forward with an acknowledgement; warnings are for review only.
 */
final readonly class Finding
{
    public function __construct(
        public FindingSeverity $severity,
        public FindingRule $rule,
        public string $message,
        public string $location = '',
        public string $hint = '',
        public ?string $ref = null,
        public ?string $questionCode = null,
        public ?string $categoryCode = null,
        public int $pointsAtStake = 0,
    ) {}
}
