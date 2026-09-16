<?php

namespace App\Policies;

use App\Models\StudentCard;
use App\Models\User;
use App\Policies\Concerns\ManagesSchoolResource;

class StudentCardPolicy
{
    use ManagesSchoolResource;

    public function view(User $user, StudentCard $card): bool
    {
        return $this->belongsToSchoolOf($user, $card->student->school_id);
    }

    public function delete(User $user, StudentCard $card): bool
    {
        return $this->manages($user, $card->student->school_id);
    }
}
