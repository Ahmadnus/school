<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Assessment;
use App\Models\User;
use App\Policies\Concerns\ManagesSchoolResource;

class AssessmentPolicy
{
    use ManagesSchoolResource;

    public function view(User $user, Assessment $assessment): bool
    {
        return $this->belongsToSchoolOf($user, $assessment->subject->grade->school_id);
    }

    public function update(User $user, Assessment $assessment): bool
    {
        return $this->manages($user, $assessment->subject->grade->school_id);
    }

    public function delete(User $user, Assessment $assessment): bool
    {
        return $this->manages($user, $assessment->subject->grade->school_id);
    }

    /**
     * Entering grades is the one academic action a teacher performs, and only
     * for a subject they are actually assigned to.
     */
    public function score(User $user, Assessment $assessment): bool
    {
        if (! $this->belongsToSchoolOf($user, $assessment->subject->grade->school_id)) {
            return false;
        }

        if ($user->role->isAdministrative()) {
            return true;
        }

        return $user->role === UserRole::Teacher
            && $user->teacherAssignments()->where('subject_id', $assessment->subject_id)->exists();
    }
}
