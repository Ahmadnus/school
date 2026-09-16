<?php

namespace App\Policies;

use App\Models\ReportCard;
use App\Models\User;
use App\Policies\Concerns\ManagesSchoolResource;

class ReportCardPolicy
{
    use ManagesSchoolResource;

    public function view(User $user, ReportCard $card): bool
    {
        return $this->belongsToSchoolOf($user, $card->student->school_id);
    }

    public function update(User $user, ReportCard $card): bool
    {
        return $this->manages($user, $card->student->school_id);
    }

    public function delete(User $user, ReportCard $card): bool
    {
        return $this->manages($user, $card->student->school_id);
    }

    /** Publishing freezes the sheet, so it stays a supervisor action. */
    public function publish(User $user, ReportCard $card): bool
    {
        return $this->manages($user, $card->student->school_id);
    }
}
