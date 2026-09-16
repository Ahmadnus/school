<?php

namespace App\Policies;

use App\Models\ScheduleSlot;
use App\Models\User;
use App\Policies\Concerns\ManagesSchoolResource;

class ScheduleSlotPolicy
{
    use ManagesSchoolResource;

    public function view(User $user, ScheduleSlot $slot): bool
    {
        return $this->belongsToSchoolOf($user, $slot->subject->grade->school_id);
    }

    public function update(User $user, ScheduleSlot $slot): bool
    {
        return $this->manages($user, $slot->subject->grade->school_id);
    }

    public function delete(User $user, ScheduleSlot $slot): bool
    {
        return $this->manages($user, $slot->subject->grade->school_id);
    }
}
