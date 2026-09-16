<?php

namespace App\Policies;

use App\Models\StudentNote;
use App\Models\User;

class StudentNotePolicy
{
    /** The author edits their own note; administrators edit any. */
    public function update(User $user, StudentNote $note): bool
    {
        if (! $user->can('manageNotes', $note->student)) {
            return false;
        }

        return $user->role->isAdministrative() || $note->author_id === $user->id;
    }

    public function delete(User $user, StudentNote $note): bool
    {
        return $this->update($user, $note);
    }
}
