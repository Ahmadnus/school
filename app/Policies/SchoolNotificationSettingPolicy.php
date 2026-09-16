<?php

namespace App\Policies;

use App\Models\SchoolNotificationSetting;
use App\Models\User;
use App\Policies\Concerns\ManagesSchoolResource;

class SchoolNotificationSettingPolicy
{
    use ManagesSchoolResource;

    /** School-wide switches are a supervisor setting. */
    public function viewAny(User $user): bool
    {
        return $user->role->isAdministrative();
    }

    public function update(User $user, SchoolNotificationSetting $setting): bool
    {
        return $this->manages($user, $setting->school_id);
    }
}
