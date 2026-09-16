<?php

namespace App\Policies;

use App\Models\FeePlan;
use App\Models\User;
use App\Policies\Concerns\ManagesSchoolResource;

class FeePlanPolicy
{
    use ManagesSchoolResource;

    /**
     * Money is administrative: teachers get no visibility at all. Guardians
     * pass viewAny so the per-plan / per-student checks below can scope them
     * to their own children (the index query does the same scoping).
     */
    public function viewAny(User $user): bool
    {
        return $user->role->isAdministrative() || $user->role->isGuardian();
    }

    /** Administrators of the school, or the guardian of this plan's student. */
    public function view(User $user, FeePlan $plan): bool
    {
        return $user->can('viewFees', $plan->student);
    }

    public function update(User $user, FeePlan $plan): bool
    {
        return $this->manages($user, $plan->student->school_id);
    }

    public function delete(User $user, FeePlan $plan): bool
    {
        return $this->manages($user, $plan->student->school_id);
    }

    /** Recording a payment against the plan. */
    public function pay(User $user, FeePlan $plan): bool
    {
        return $this->manages($user, $plan->student->school_id);
    }
}
