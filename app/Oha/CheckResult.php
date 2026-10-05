<?php

namespace App\Oha;

use App\Enums\FindingSeverity;

/**
 * The outcome of checking an OHA form. $ok means no blocking error was found.
 */
final readonly class CheckResult
{
    /**
     * @param  list<Finding>  $findings
     * @param  array<string, int|null>|null  $points  null when the form was refused
     * @param  array<string, mixed>  $cover  movement facts and sign-off read from the form
     * @param  array<string, int>  $pointsAtStake  category code => points left unanswered
     */
    public function __construct(
        public bool $ok,
        public array $findings,
        public ?array $points,
        public array $cover,
        public array $pointsAtStake,
    ) {}

    /**
     * @return list<Finding>
     */
    public function withSeverity(FindingSeverity $severity): array
    {
        return array_values(array_filter($this->findings, fn (Finding $f) => $f->severity === $severity));
    }

    /**
     * @return list<string>
     */
    public function gapRefs(): array
    {
        return array_map(fn (Finding $f) => (string) $f->ref, $this->withSeverity(FindingSeverity::Missing));
    }

    public function totalPointsAtStake(): int
    {
        return array_sum($this->pointsAtStake);
    }
}
