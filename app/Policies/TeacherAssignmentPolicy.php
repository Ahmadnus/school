<?php

namespace App\Policies;

use App\Models\TeacherAssignment;
use App\Models\User;
use App\Policies\Concerns\ManagesSchoolResource;

class TeacherAssignmentPolicy
{
    use ManagesSchoolResource;

    public function view(User $user, TeacherAssignment $assignment): bool
    {
        return $this->belongsToSchoolOf($user, $assignment->teacher->school_id);
    }

    public function update(User $user, TeacherAssignment $assignment): bool
    {
        return $this->manages($user, $assignment->teacher->school_id);
    }

    public function delete(User $user, TeacherAssignment $assignment): bool
    {
        return $this->manages($user, $assignment->teacher->school_id);
    }
}
