<?php

namespace App\Policies;

use App\Models\StudentImport;
use App\Models\User;
use App\Policies\Concerns\ManagesSchoolResource;

class StudentImportPolicy
{
    use ManagesSchoolResource;

    /** Bulk-creating students is an administrative action. */
    public function viewAny(User $user): bool
    {
        return $user->role->isAdministrative();
    }

    public function view(User $user, StudentImport $import): bool
    {
        return $this->manages($user, $import->school_id);
    }

    public function update(User $user, StudentImport $import): bool
    {
        return $this->manages($user, $import->school_id);
    }

    public function delete(User $user, StudentImport $import): bool
    {
        return $this->manages($user, $import->school_id) && ! $import->isCommitted();
    }
}
