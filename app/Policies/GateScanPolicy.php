<?php

namespace App\Policies;

use App\Models\GateScan;
use App\Models\User;
use App\Policies\Concerns\ManagesSchoolResource;

class GateScanPolicy
{
    use ManagesSchoolResource;

    public function view(User $user, GateScan $scan): bool
    {
        return $this->belongsToSchoolOf($user, $scan->school_id);
    }
}
