<?php

namespace App\Policies;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\User;
use App\Policies\Concerns\ManagesSchoolResource;

class PostPolicy
{
    use ManagesSchoolResource;

    public function view(User $user, Post $post): bool
    {
        if (! $this->belongsToSchoolOf($user, $post->school_id)) {
            return false;
        }

        // Unpublished posts are visible to their author and to reviewers.
        return $post->status === PostStatus::Published
            || $post->author_id === $user->id
            || $user->role->isAdministrative();
    }

    /** The type itself decides who may create it (its min_role chips). */
    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Post $post): bool
    {
        if (! $this->belongsToSchoolOf($user, $post->school_id)) {
            return false;
        }

        // A published post is no longer editable by its author alone.
        if ($post->status === PostStatus::Published) {
            return $user->role->isAdministrative();
        }

        return $post->author_id === $user->id || $user->role->isAdministrative();
    }

    public function delete(User $user, Post $post): bool
    {
        return $this->update($user, $post);
    }

    /** Approving is a supervisor decision, and never of one's own post. */
    public function review(User $user, Post $post): bool
    {
        return $this->manages($user, $post->school_id) && $post->author_id !== $user->id;
    }
}
