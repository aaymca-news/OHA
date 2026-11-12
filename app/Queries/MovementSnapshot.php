<?php

namespace App\Queries;

use App\Models\Assessment;
use App\Models\Baseline;
use App\Models\FormUpload;
use App\Models\MovementStatus;
use App\Oha\CategoryRows;
use App\Oha\Profile;

/**
 * A movement as its latest approved OHA form describes it: the scored assessment, its
 * category rows, and the profile read from the frozen form. Shown on the movement's page
 * and on its own dashboard.
 */
final readonly class MovementSnapshot
{
    /**
     * @param  list<array<string, mixed>>  $rows  from CategoryRows::for()
     * @param  array<string, mixed>|null  $profile  from Profile::toArray()
     */
    private function __construct(
        public ?Assessment $latest,
        public array $rows,
        public ?array $profile,
    ) {}

    public static function of(MovementStatus $status): self
    {
        $latest = $status->latest_assessment_id !== null ? Assessment::query()->with('score.band')->find($status->latest_assessment_id) : null;
        if ($latest === null) {
            return new self(null, [], null);
        }

        // What the latest approved form says about the movement, beyond its score.
        $frozen = FormUpload::query()->whereKey($latest->categoryScores()->value('form_upload_id'))->first();

        return new self(
            $latest,
            CategoryRows::for($latest),
            // The answers as they stand: the newest baseline's, once it has one.
            $frozen !== null ? (new Profile(Baseline::newestAnswers([$latest->id])[$latest->id] ?? $frozen->answers ?? [], $frozen->form_meta ?? []))->toArray() : null,
        );
    }
}
