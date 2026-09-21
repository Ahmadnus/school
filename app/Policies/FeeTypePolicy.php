<?php

namespace App\Policies;

use App\Models\FeeType;
use App\Models\User;
use App\Policies\Concerns\ManagesSchoolResource;

class FeeTypePolicy
{
    use ManagesSchoolResource;

    /**
     * Fee types are prices, and prices are money: the shared trait opens
     * reading to everyone in the school, which handed a teacher the whole
     * tuition sheet. A teacher's work never touches what a family pays.
     */
    public function viewAny(User $user): bool
    {
        return $user->role->isAdministrative();
    }

    public function view(User $user, FeeType $type): bool
    {
        return $this->manages($user, $type->school_id);
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
