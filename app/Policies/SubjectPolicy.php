<?php

namespace App\Policies;

use App\Models\Subject;
use App\Models\User;
use App\Policies\Concerns\ManagesSchoolResource;

class SubjectPolicy
{
    use ManagesSchoolResource;

    public function view(User $user, Subject $subject): bool
    {
        return $this->belongsToSchoolOf($user, $subject->grade->school_id);
    }

    public function update(User $user, Subject $subject): bool
    {
        return $this->manages($user, $subject->grade->school_id);
    }

    public function delete(User $user, Subject $subject): bool
    {
        return $this->manages($user, $subject->grade->school_id);
    }

    /** Assigning teachers to this subject (decision 15-b). */
    public function assign(User $user, Subject $subject): bool
    {
        return $this->manages($user, $subject->grade->school_id);
    }
}
