<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Section;
use App\Models\User;
use App\Policies\Concerns\ManagesSchoolResource;

class SectionPolicy
{
    use ManagesSchoolResource;

    public function view(User $user, Section $section): bool
    {
        return $this->belongsToSchoolOf($user, $section->grade->school_id);
    }

    public function update(User $user, Section $section): bool
    {
        return $this->manages($user, $section->grade->school_id);
    }

    public function delete(User $user, Section $section): bool
    {
        return $this->manages($user, $section->grade->school_id);
    }

    /**
     * Taking attendance is wider than editing the section: administrators
     * anywhere, supervisors within their scope (decision 7-a), and teachers
     * in the sections they actually teach.
     */
    public function takeAttendance(User $user, Section $section): bool
    {
        if (! $this->belongsToSchoolOf($user, $section->grade->school_id)) {
            return false;
        }

        if ($user->role->isAdministrative()) {
            return true;
        }

        if ($user->supervisedSections()->whereKey($section->id)->exists()) {
            return true;
        }

        return $user->role === UserRole::Teacher
            && $user->teacherAssignments()->where('section_id', $section->id)->exists();
    }
}
