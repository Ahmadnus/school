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
    public function viewAny(User $user): bool
    {
        return ! $user->role->isGuardian();
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
