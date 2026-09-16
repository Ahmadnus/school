<?php

namespace App\Policies;

use App\Models\AcademicYear;
use App\Models\User;
use App\Policies\Concerns\ManagesSchoolResource;

class AcademicYearPolicy
{
    use ManagesSchoolResource;

    public function view(User $user, AcademicYear $year): bool
    {
        return $this->belongsToSchoolOf($user, $year->school_id);
    }

    public function update(User $user, AcademicYear $year): bool
    {
        return $this->manages($user, $year->school_id);
    }

    public function delete(User $user, AcademicYear $year): bool
    {
        return $this->manages($user, $year->school_id);
    }
}
