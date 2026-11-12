<?php

namespace App\Demo;

use App\Enums\Role;
use App\Models\Movement;
use App\Models\User;

/**
 * Who a visitor can be in their demo, one click each from "Choose who to be": the AAYMCA
 * staff (the Administrator, and the AAYMCA assessor, assigned to every movement), and any
 * movement's Board Chairperson or National General Secretary. All invented, at
 * example.org (a domain that never receives mail), made with every new demo (DemoSeeder).
 */
final class DemoPeople
{
    /** @var array<string, array{email: string, label: string, explains: string}> AAYMCA staff: key => person */
    public const STAFF = [
        'admin' => ['email' => 'demo.admin@example.org', 'label' => 'Administrator', 'explains' => 'Approves, assigns assessors, and can do all an assessor does, on any movement'],
        'assessor' => ['email' => 'demo.assessor@example.org', 'label' => 'AAYMCA assessor', 'explains' => 'Runs the assessments, closes them, and runs the follow-ups and re-scoring, for every movement'],
    ];

    /** @var array<string, array{role: Role, label: string, explains: string}> a movement's people: key => role */
    public const MOVEMENT = [
        'chair' => ['role' => Role::Board, 'label' => 'Board Chairperson', 'explains' => 'Reads the approved report, may sign the ODP'],
        'ngs' => ['role' => Role::Ngs, 'label' => 'National General Secretary', 'explains' => 'Adds evidence, and notes on the ODP tasks between follow-ups'],
    ];

    /** @return list<string> every key a visitor can choose */
    public static function keys(): array
    {
        return [...array_keys(self::STAFF), ...array_keys(self::MOVEMENT)];
    }

    /**
     * Whether this is one of the demo's own people (the Administrator, the assessor, a
     * movement's Board Chairperson or General Secretary): on the demo, all of them, every
     * one at example.org. Visitors are each of them in turn, so none may be deactivated
     * or deleted there: in the one shared demo it would take the role away from everyone.
     */
    public static function isBuiltIn(User $user): bool
    {
        return (bool) config('oha.demo') && str_ends_with(mb_strtolower($user->email), '@example.org');
    }

    /** The demo person for a key (and, for a movement's people, the movement), if there is one. */
    public static function find(string $key, ?Movement $movement = null): ?User
    {
        $people = User::query()->where('active', true);
        if (isset(self::STAFF[$key])) {
            return $people->where('email', self::STAFF[$key]['email'])->first();
        }
        if (isset(self::MOVEMENT[$key]) && $movement !== null) {
            return $people->where('role', self::MOVEMENT[$key]['role'])->where('movement_id', $movement->id)->first();
        }

        return null;
    }
}
