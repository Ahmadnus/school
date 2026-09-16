<?php

namespace App\Policies;

use App\Models\FeeType;
use App\Models\User;
use App\Policies\Concerns\ManagesSchoolResource;

class FeeTypePolicy
{
    use ManagesSchoolResource;

    public function view(User $user, FeeType $type): bool
    {
        return $this->belongsToSchoolOf($user, $type->school_id);
    }

    public function update(User $user, FeeType $type): bool
    {
        return $this->manages($user, $type->school_id);
    }

    public function delete(User $user, FeeType $type): bool
    {
        return $this->manages($user, $type->school_id);
    }
}
