<?php

namespace App\Policies;

use App\Models\Rubric;
use App\Models\User;
use App\Policies\Concerns\ManagesSchoolResource;

class RubricPolicy
{
    use ManagesSchoolResource;

    public function view(User $user, Rubric $rubric): bool
    {
        return $this->belongsToSchoolOf($user, $rubric->school_id);
    }

    public function update(User $user, Rubric $rubric): bool
    {
        return $this->manages($user, $rubric->school_id);
    }

    public function delete(User $user, Rubric $rubric): bool
    {
        return $this->manages($user, $rubric->school_id);
    }
}
