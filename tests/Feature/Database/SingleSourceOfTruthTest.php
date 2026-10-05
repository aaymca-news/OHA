<?php

use App\Enums\ArtefactKind;
use App\Enums\ArtefactState;
use App\Enums\CommentKind;
use App\Enums\DocumentFormat;
use App\Enums\DocumentPurpose;
use App\Enums\DocumentSource;
use App\Enums\DqaDimension;
use App\Enums\FindingSeverity;
use App\Enums\HolderRole;
use App\Enums\Role;
use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Category;
use App\Models\CategoryScore;
use App\Models\MovementStatus;
use Database\Seeders\MovementsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

it('holds the category weights in the database, totalling 84 points', function () {
    expect((int) Category::query()->sum('max_points'))->toBe(84)
        ->and(Category::query()->where('code', 'property')->value('max_points'))->toBeNull()
        ->and(Category::query()->weighted()->count())->toBe(8);
});

it('keeps a validated score frozen when a weight changes later', function () {
    $assessment = Assessment::factory()->create();
    $governance = Category::query()->where('code', 'governance')->firstOrFail();

    CategoryScore::factory()->create([
        'assessment_id' => $assessment->id,
        'category_id' => $governance->id,
        'points' => 9,
        'max_points_at_scoring' => $governance->max_points,
    ]);

    $governance->update(['max_points' => 15]);

    $score = AssessmentScore::query()->findOrFail($assessment->id);

    expect($score->points_available)->toBe(12)
        ->and((float) $score->pct)->toBe(75.0);
});

it('never hard-codes a category weight or band limit in application code', function () {
    $codes = Category::query()->pluck('code')->implode('|');
    $weightMap = "/['\"]({$codes})['\"]\\s*=>\\s*\\d/";
    $bandLimit = '/\b(pct|percent|percentage|score)\b\s*[<>]=?\s*(90|70|50|20)\b/i';

    $offenders = collect(File::allFiles(app_path()))
        ->filter(fn ($file) => $file->getExtension() === 'php')
        ->filter(fn ($file) => preg_match($weightMap, $file->getContents()) || preg_match($bandLimit, $file->getContents()))
        ->map(fn ($file) => $file->getRelativePathname())
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

it('refuses to save or delete a model that reads a view', function () {
    $this->seed(MovementsSeeder::class);
    $status = MovementStatus::query()->firstOrFail();

    expect(fn () => $status->save())->toThrow(LogicException::class)
        ->and(fn () => $status->delete())->toThrow(LogicException::class);
});

/*
 * Each PHP enum must list exactly the values its database CHECK constraint allows,
 * so the two can never drift apart.
 */
dataset('enum constraints', [
    'role' => [Role::class, 'users_role_valid'],
    'artefact kind' => [ArtefactKind::class, 'artefacts_kind_valid'],
    'artefact state' => [ArtefactState::class, 'artefacts_state_valid_for_kind'],
    'finding severity' => [FindingSeverity::class, 'form_findings_severity_valid'],
    'dqa dimension' => [DqaDimension::class, 'form_findings_dqa_valid'],
    'comment kind' => [CommentKind::class, 'artefact_comments_kind_valid'],
    'holder role' => [HolderRole::class, 'turnaround_rules_holder_role'],
    'document purpose' => [DocumentPurpose::class, 'documents_purpose_valid'],
    'document format' => [DocumentFormat::class, 'documents_format_valid'],
    'document source' => [DocumentSource::class, 'documents_source_valid'],
]);

it('keeps each enum identical to its database constraint', function (string $enum, string $constraint) {
    $definition = DB::scalar('SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = ?', [$constraint]);
    preg_match_all("/'([a-z_]+)'::/", (string) $definition, $matches);

    $inDatabase = collect($matches[1])->unique()->sort()->values()->all();
    $inEnum = collect($enum::cases())->map(fn ($case) => $case->value)->sort()->values()->all();

    // The artefact-state constraint also names the artefact kinds.
    if ($enum === ArtefactState::class) {
        $inDatabase = array_values(array_diff($inDatabase, ['form', 'report', 'odp']));
    }

    expect($inDatabase)->toBe($inEnum);
})->with('enum constraints');
