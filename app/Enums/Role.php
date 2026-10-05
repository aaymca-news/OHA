<?php

namespace App\Enums;

/**
 * Role a Secretariat or National Movement user holds.
 *
 * AAYMCA: Staff, Administrator and Super Administrator. Both kinds of Administrator
 * approve and manage users alike; only a Super Administrator gives or takes away an
 * Administrator role. A National Movement has one user, its Board Chairperson.
 */
enum Role: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Staff = 'staff';
    case Board = 'board';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Administrator',
            self::Admin => 'Administrator',
            self::Staff => 'AAYMCA Staff',
            self::Board => 'Board Chairperson',
        };
    }

    /** The Administrator roles, which only a Super Administrator gives or takes away. */
    public function isAdministrator(): bool
    {
        return $this === self::Admin || $this === self::SuperAdmin;
    }
}
