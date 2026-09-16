<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->isAdministrative();
    }

    public function view(User $user, User $model): bool
    {
        return $user->id === $model->id
            || ($user->school_id === $model->school_id && $user->role->isAdministrative());
    }

    public function create(User $user): bool
    {
        return $user->role->isAdministrative();
    }

    public function update(User $user, User $model): bool
    {
        if ($user->school_id !== $model->school_id) {
            return false;
        }

        // Only a super admin may touch another super admin.
        if ($model->role === UserRole::SuperAdmin && $user->role !== UserRole::SuperAdmin) {
            return false;
        }

        return $user->role->isAdministrative();
    }

    public function delete(User $user, User $model): bool
    {
        return $user->id !== $model->id && $this->update($user, $model);
    }
}
