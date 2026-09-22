<?php

namespace App\Enums;

enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Teacher = 'teacher';
    case Driver = 'driver';

    // / Parent/guardian account — signs in on the guardian app by phone + OTP.
    case Guardian = 'guardian';

    /** Roles that manage the school: setup, people, academics. */
    public function isAdministrative(): bool
    {
        return in_array($this, [self::SuperAdmin, self::Admin], true);
    }

    /** Guardians see only their own children; never the staff app. */
    public function isGuardian(): bool
    {
        return $this === self::Guardian;
    }

    public function label(): string
    {
        return __('roles.'.$this->value);
    }
}
