<?php

namespace App\Policies;

use App\Models\PostType;
use App\Models\User;
use App\Policies\Concerns\ManagesSchoolResource;

class PostTypePolicy
{
    use ManagesSchoolResource;

    public function view(User $user, PostType $type): bool
    {
        return $this->belongsToSchoolOf($user, $type->school_id);
    }

    /** Only the general supervisor rewires who may post what. */
    public function update(User $user, PostType $type): bool
    {
        return $this->manages($user, $type->school_id);
    }
}
