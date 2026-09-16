<?php

namespace App\Policies;

use App\Models\AssessmentType;
use App\Models\User;
use App\Policies\Concerns\ManagesSchoolResource;

class AssessmentTypePolicy
{
    use ManagesSchoolResource;

    public function view(User $user, AssessmentType $type): bool
    {
        return $this->belongsToSchoolOf($user, $type->school_id);
    }

    public function update(User $user, AssessmentType $type): bool
    {
        return $this->manages($user, $type->school_id);
    }

    public function delete(User $user, AssessmentType $type): bool
    {
        return $this->manages($user, $type->school_id);
    }
}
