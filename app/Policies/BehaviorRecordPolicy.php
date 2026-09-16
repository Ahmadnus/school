<?php

namespace App\Policies;

use App\Models\BehaviorRecord;
use App\Models\User;

class BehaviorRecordPolicy
{
    public function view(User $user, BehaviorRecord $record): bool
    {
        if (! $user->can('viewBehavior', $record->student)) {
            return false;
        }

        return $user->role->isGuardian() ? $record->visible_to_guardian : true;
    }

    /** The recorder edits their own record; administrators edit any. */
    public function update(User $user, BehaviorRecord $record): bool
    {
        if (! $user->can('manageBehavior', $record->student)) {
            return false;
        }

        return $user->role->isAdministrative() || $record->recorded_by === $user->id;
    }

    public function delete(User $user, BehaviorRecord $record): bool
    {
        return $this->update($user, $record);
    }
}
