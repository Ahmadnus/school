<?php

namespace App\Policies\Concerns;

use App\Models\User;

/**
 * Shared shape for school-owned resources: everyone in the school reads,
 * only administrative roles write.
 */
trait ManagesSchoolResource
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->role->isAdministrative();
    }

    protected function belongsToSchoolOf(User $user, int $schoolId): bool
    {
        return $user->school_id === $schoolId;
    }

    protected function manages(User $user, int $schoolId): bool
    {
        return $this->belongsToSchoolOf($user, $schoolId) && $user->role->isAdministrative();
    }
}
