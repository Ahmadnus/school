<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

class ConversationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    /** A thread is readable only by its participants. */
    public function view(User $user, Conversation $conversation): bool
    {
        return $user->school_id === $conversation->school_id
            && $conversation->participantRecords()->where('user_id', $user->id)->exists();
    }

    public function reply(User $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }

    /** Flagging for follow-up is office work: any staff participant, never a guardian. */
    public function flag(User $user, Conversation $conversation): bool
    {
        return ! $user->role->isGuardian() && $this->view($user, $conversation);
    }

    /** Only supervisors move a thread through its status lifecycle. */
    public function updateStatus(User $user, Conversation $conversation): bool
    {
        return $user->school_id === $conversation->school_id && $user->role->isAdministrative();
    }
}
