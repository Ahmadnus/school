<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Student;
use App\Models\User;
use App\Policies\Concerns\ManagesSchoolResource;

/**
 * Who may see what on a student.
 *
 * - Administrative roles: everything in their school.
 * - Teachers: the roster and academic/attendance/behaviour data; no money.
 * - Drivers: the roster only (they need names for transport), nothing else.
 * - Guardians: their own children only, and only what the school shares —
 *   never internal notes, only behaviour records flagged visible_to_guardian.
 *
 * Every rule here is enforced server-side; the apps merely mirror it.
 */
class StudentPolicy
{
    use ManagesSchoolResource;

    public function view(User $user, Student $student): bool
    {
        if (! $this->belongsToSchoolOf($user, $student->school_id)) {
            return false;
        }

        return $user->role->isGuardian() ? $student->isGuardedBy($user) : true;
    }

    public function update(User $user, Student $student): bool
    {
        return $this->manages($user, $student->school_id);
    }

    public function delete(User $user, Student $student): bool
    {
        return $this->manages($user, $student->school_id);
    }

    /** Enrolling / moving a student between sections. */
    public function enroll(User $user, Student $student): bool
    {
        return $this->manages($user, $student->school_id);
    }

    /** Subjects, scores, attendance history — staff and the child's guardian. */
    public function viewAcademic(User $user, Student $student): bool
    {
        return $this->view($user, $student) && $user->role !== UserRole::Driver;
    }

    /** Internal notes are staff-only; guardians and drivers never see them. */
    public function viewNotes(User $user, Student $student): bool
    {
        return $this->belongsToSchoolOf($user, $student->school_id)
            && in_array($user->role, [UserRole::SuperAdmin, UserRole::Admin, UserRole::Teacher], true);
    }

    public function manageNotes(User $user, Student $student): bool
    {
        return $this->viewNotes($user, $student);
    }

    /** Guardians may read shared behaviour records; the controller filters them. */
    public function viewBehavior(User $user, Student $student): bool
    {
        return $this->viewAcademic($user, $student);
    }

    /** Recording behaviour is a teacher's or administrator's job. */
    public function manageBehavior(User $user, Student $student): bool
    {
        return $this->belongsToSchoolOf($user, $student->school_id)
            && in_array($user->role, [UserRole::SuperAdmin, UserRole::Admin, UserRole::Teacher], true);
    }

    /** An excuse is filed by the office or by the child's own guardian. */
    public function submitExcuse(User $user, Student $student): bool
    {
        if (! $this->belongsToSchoolOf($user, $student->school_id)) {
            return false;
        }

        if ($user->role->isAdministrative()) {
            return true;
        }

        return $user->role->isGuardian() && $student->isGuardedBy($user);
    }

    /** Money: administrators, plus the guardian of this particular child. */
    public function viewFees(User $user, Student $student): bool
    {
        if (! $this->belongsToSchoolOf($user, $student->school_id)) {
            return false;
        }

        if ($user->role->isAdministrative()) {
            return true;
        }

        return $user->role->isGuardian() && $student->isGuardedBy($user);
    }
}
