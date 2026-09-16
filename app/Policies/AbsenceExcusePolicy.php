<?php

namespace App\Policies;

use App\Models\AbsenceExcuse;
use App\Models\User;
use App\Policies\Concerns\ManagesSchoolResource;

class AbsenceExcusePolicy
{
    use ManagesSchoolResource;

    public function view(User $user, AbsenceExcuse $excuse): bool
    {
        return $user->can('viewAcademic', $excuse->student);
    }

    public function update(User $user, AbsenceExcuse $excuse): bool
    {
        return $this->manages($user, $excuse->student->school_id);
    }

    public function delete(User $user, AbsenceExcuse $excuse): bool
    {
        return $this->manages($user, $excuse->student->school_id);
    }

    /** Accepting or rejecting is a supervisor decision. */
    public function review(User $user, AbsenceExcuse $excuse): bool
    {
        return $this->manages($user, $excuse->student->school_id);
    }
}
