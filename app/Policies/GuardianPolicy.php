<?php

namespace App\Policies;

use App\Models\Guardian;
use App\Models\User;
use App\Policies\Concerns\ManagesSchoolResource;

class GuardianPolicy
{
    use ManagesSchoolResource;

    public function view(User $user, Guardian $guardian): bool
    {
        return $this->belongsToSchoolOf($user, $guardian->school_id);
    }

    public function update(User $user, Guardian $guardian): bool
    {
        return $this->manages($user, $guardian->school_id);
    }

    public function delete(User $user, Guardian $guardian): bool
    {
        return $this->manages($user, $guardian->school_id);
    }
}
