<?php

namespace App\Policies;

use App\Models\Attachment;
use App\Models\User;
use App\Policies\Concerns\ManagesSchoolResource;

class AttachmentPolicy
{
    use ManagesSchoolResource;

    public function view(User $user, Attachment $attachment): bool
    {
        return $this->belongsToSchoolOf($user, $attachment->school_id);
    }

    public function delete(User $user, Attachment $attachment): bool
    {
        return $attachment->uploaded_by === $user->id
            || $this->manages($user, $attachment->school_id);
    }
}
