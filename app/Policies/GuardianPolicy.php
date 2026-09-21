<?php

namespace App\Policies;

use App\Models\Guardian;
use App\Models\User;
use App\Policies\Concerns\ManagesSchoolResource;

class GuardianPolicy
{
    use ManagesSchoolResource;

    /**
     * دليل أولياء الأمور للكادر وحده.
     *
     * القاعدة الموروثة «كل من في المدرسة يقرأ» تصلح للصفوف والمواد، ولا تصلح
     * هنا: القائمة تحمل أسماء العائلات وأرقام هواتفها، فكان أيّ وليّ أمر
     * يقرأ دليل المدرسة كلّه.
     */
    /**
     * The guardian directory is every parent's phone number in one list.
     *
     * A teacher needs the contacts of *their own* students, and gets them
     * from the student profile, scoped one student at a time. The directory
     * itself is office work, so it stays with the office.
     */
    public function viewAny(User $user): bool
    {
        return $user->role->isAdministrative();
    }

    public function view(User $user, Guardian $guardian): bool
    {
        return $this->belongsToSchoolOf($user, $guardian->school_id);
    }

    public function update(User $user, Guardian $guardian): bool
    {
        return $this->manages($user, $guardian->school_id);
    }

    public function delete(User $user, Guardian $guardian): bool
    {
        return $this->manages($user, $guardian->school_id);
    }
}
