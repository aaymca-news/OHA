<?php

namespace App\Oha;

use Closure;

/**
 * One question on the OHA form.
 *
 * $required is true, false, or a closure over all answers returning the reason
 * the question is required (or false). $points mirrors the cell's Excel formula.
 */
final readonly class Question
{
    /**
     * @param  list<string>|null  $options
     * @param  bool|Closure(array<string, mixed>): (string|false)  $required
     * @param  (Closure(array<string, mixed>): int)|null  $points
     */
    public function __construct(
        public string $code,
        public string $type,
        public ?array $options = null,
        public bool|Closure $required = true,
        public ?int $max = null,
        public ?Closure $points = null,
        public ?string $group = null,
        public ?string $notesFrom = null,
    ) {}

    /**
     * Why this question must be answered, given the other answers; false if it need not be.
     *
     * @param  array<string, mixed>  $answers
     */
    public function requiredBecause(array $answers): string|false
    {
        if ($this->required === true) {
            return 'Required.';
        }

        if ($this->required === false) {
            return false;
        }

        return ($this->required)($answers);
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    public function score(array $answers): int
    {
        return $this->points === null ? 0 : ($this->points)($answers);
    }

    /** The code as shown to people: the second use of a code is "Q325 (2nd)". */
    public function displayCode(): string
    {
        return str_replace('#2', ' (2nd)', $this->code);
    }
}
