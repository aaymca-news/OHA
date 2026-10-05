<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Movement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state: a Secretariat staff member.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => Role::Staff,
            'title' => fake()->randomElement(['Zonal Coordinator', 'Programme Officer', 'Movement Strengthening Officer', 'Finance Officer']),
            'movement_id' => null,
            'active' => true,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function role(Role $role): static
    {
        return $this->state(fn (array $attributes) => ['role' => $role]);
    }

    public function admin(): static
    {
        return $this->role(Role::Admin);
    }

    public function superAdmin(): static
    {
        return $this->role(Role::SuperAdmin);
    }

    /**
     * The Board Chairperson of the given (or a new) movement: its one user, who signs its ODP.
     */
    public function chair(?Movement $movement = null): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => Role::Board,
            'movement_id' => $movement !== null ? $movement->id : Movement::factory(),
        ]);
    }

    /** Invited, but has not yet followed the link to set a password. */
    public function invitationPending(): static
    {
        return $this->state(fn (array $attributes) => ['email_verified_at' => null]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['active' => false]);
    }
}
