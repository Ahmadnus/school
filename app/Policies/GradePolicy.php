<?php

namespace App\Policies;

use App\Models\Grade;
use App\Models\User;
use App\Policies\Concerns\ManagesSchoolResource;

class GradePolicy
{
    use ManagesSchoolResource;

    public function view(User $user, Grade $grade): bool
    {
        return $this->belongsToSchoolOf($user, $grade->school_id);
    }

    public function update(User $user, Grade $grade): bool
    {
        return $this->manages($user, $grade->school_id);
    }

    public function delete(User $user, Grade $grade): bool
    {
        return $this->manages($user, $grade->school_id);
    }
}
