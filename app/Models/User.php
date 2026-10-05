<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'title', 'movement_id', 'active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Match the column defaults, so a user created in code knows them before it is re-read.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => 'staff',
        'active' => true,
    ];

    /**
     * Movements this staff member is assigned to assess.
     *
     * @return BelongsToMany<Movement, $this>
     */
    public function assignedMovements(): BelongsToMany
    {
        return $this->belongsToMany(Movement::class);
    }

    /**
     * The movement whose Board Chairperson this is. NULL for Secretariat users.
     *
     * @return BelongsTo<Movement, $this>
     */
    public function movement(): BelongsTo
    {
        return $this->belongsTo(Movement::class);
    }

    /**
     * Every AAYMCA user (Staff and both kinds of Administrator) may assess the
     * movements they are assigned to. Only the Administrators assign them.
     */
    public function canAssess(Movement $movement): bool
    {
        return $this->active
            && $this->isAssessor()
            && $this->assignedMovements()->whereKey($movement->id)->exists();
    }

    public function isSecretariat(): bool
    {
        return $this->role !== Role::Board;
    }

    /** Staff and both kinds of Administrator assess. */
    public function isAssessor(): bool
    {
        return $this->isSecretariat();
    }

    /**
     * An Administrator or a Super Administrator: they approve the form, report and
     * ODP, see all work in progress, assign assessors and manage users.
     */
    public function isAdmin(): bool
    {
        return $this->role->isAdministrator();
    }

    /** The only role that gives or takes away an Administrator role. */
    public function isSuperAdmin(): bool
    {
        return $this->role === Role::SuperAdmin;
    }

    /** Those who see every assessment while it is still in progress, and its audit trail. */
    public function oversees(): bool
    {
        return $this->active && $this->isAdmin();
    }

    /** Only the Administrators assign staff to assess a movement. */
    public function canAssignAssessors(): bool
    {
        return $this->active && $this->isAdmin();
    }

    /** The Board Chairperson of this movement: its one user, who signs its ODP. */
    public function isChairOf(Movement $movement): bool
    {
        return $this->role === Role::Board && $this->movement_id === $movement->id;
    }

    /** An invited user who has not yet set a password. */
    public function isInvitationPending(): bool
    {
        return $this->email_verified_at === null;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'active' => 'boolean',
        ];
    }
}
