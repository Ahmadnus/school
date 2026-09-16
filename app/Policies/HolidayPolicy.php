<?php

namespace App\Policies;

use App\Models\Holiday;
use App\Models\User;
use App\Policies\Concerns\ManagesSchoolResource;

class HolidayPolicy
{
    use ManagesSchoolResource;

    public function view(User $user, Holiday $holiday): bool
    {
        return $this->belongsToSchoolOf($user, $holiday->school_id);
    }

    public function update(User $user, Holiday $holiday): bool
    {
        return $this->manages($user, $holiday->school_id);
    }

    public function delete(User $user, Holiday $holiday): bool
    {
        return $this->manages($user, $holiday->school_id);
    }
}
