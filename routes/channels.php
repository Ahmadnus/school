<?php

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/**
 * Private channels for the app. Authorisation runs through the same Sanctum
 * guard the REST API uses (see the broadcasting route in bootstrap/app.php),
 * so the client only needs its bearer token.
 */

// The stream a user listens to for their own device alerts.
Broadcast::channel('user.{id}', function (User $user, int $id) {
    return $user->id === $id;
});

// A conversation is readable only by its participants.
Broadcast::channel('conversation.{conversationId}', function (User $user, int $conversationId) {
    $conversation = Conversation::query()
        ->where('school_id', $user->school_id)
        ->find($conversationId);

    return $conversation !== null
        && $conversation->participantRecords()->where('user_id', $user->id)->exists();
});
