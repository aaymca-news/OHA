<?php

namespace Database\Factories;

use App\Enums\CommentKind;
use App\Models\Artefact;
use App\Models\ArtefactComment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ArtefactComment>
 */
class ArtefactCommentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'artefact_id' => Artefact::factory(),
            'user_id' => User::factory(),
            'kind' => CommentKind::Comment,
            'body' => fake()->sentence(),
            'created_at' => now(),
        ];
    }
}
