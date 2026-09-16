<?php

namespace App\Policies;

use App\Models\School;
use App\Models\User;

class SchoolPolicy
{
    public function view(User $user, School $school): bool
    {
        return $user->school_id === $school->id;
    }

    /** School identity is owner-level configuration. */
    public function update(User $user, School $school): bool
    {
        return $user->school_id === $school->id && $user->role->isAdministrative();
    }
}
