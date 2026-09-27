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

    /**
     * قراءة جدول حصص أستاذ.
     *
     * جدول الأستاذ نفسه حقٌّ له لا مِنّة: هو من يُدرّسه. والإدارة ترى الجميع.
     * والمشرف يرى من يدرّس في شعبته وحده — حدود إشرافه لا أكثر، فلا يصير
     * الإشراف على شعبةٍ بابًا إلى أوقات الكادر كلّه.
     */
    public function viewTimetable(User $user, User $teacher): bool
    {
        if ($user->school_id !== $teacher->school_id) {
            return false;
        }

        if ($user->id === $teacher->id || $user->role->isAdministrative()) {
            return true;
        }

        return $user->supervisedSections()
            ->whereIn(
                'sections.id',
                $teacher->teacherAssignments()->select('section_id'),
            )
            ->exists();
    }
}
