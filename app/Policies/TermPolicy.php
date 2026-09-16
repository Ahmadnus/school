<?php

namespace App\Policies;

use App\Models\Term;
use App\Models\User;
use App\Policies\Concerns\ManagesSchoolResource;

class TermPolicy
{
    use ManagesSchoolResource;

    public function view(User $user, Term $term): bool
    {
        return $this->belongsToSchoolOf($user, $term->academicYear->school_id);
    }

    public function update(User $user, Term $term): bool
    {
        return $this->manages($user, $term->academicYear->school_id);
    }

    public function delete(User $user, Term $term): bool
    {
        return $this->manages($user, $term->academicYear->school_id);
    }
}
