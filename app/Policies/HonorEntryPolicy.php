<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\HonorEntry;
use App\Models\User;
use App\Policies\Concerns\ManagesSchoolResource;

/**
 * التكريم فعل تربوي يملكه الأستاذ، لا الإدارة وحدها: من يدرّس الطالب هو من
 * يرى تحسّنه. لذلك للأستاذ أن ينشئ وينشر تكريماً — لكن لا يعدّل ولا يحذف
 * تكريم زميله.
 */
class HonorEntryPolicy
{
    use ManagesSchoolResource;

    /** اللوحة يراها الجميع، الأهالي والكادر. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, HonorEntry $entry): bool
    {
        return $this->belongsToSchoolOf($user, $entry->school_id);
    }

    public function create(User $user): bool
    {
        return $user->role->isAdministrative() || $user->role === UserRole::Teacher;
    }

    public function update(User $user, HonorEntry $entry): bool
    {
        if (! $this->belongsToSchoolOf($user, $entry->school_id)) {
            return false;
        }

        return $user->role->isAdministrative() || $entry->awarded_by === $user->id;
    }

    public function delete(User $user, HonorEntry $entry): bool
    {
        return $this->update($user, $entry);
    }
}
