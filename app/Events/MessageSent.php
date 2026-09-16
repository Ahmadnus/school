<?php

namespace App\Events;

use App\Http\Resources\MessageResource;
use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A new message in a conversation, pushed over Reverb to everyone taking part.
 *
 * ShouldBroadcastNow, not ShouldBroadcast: chat is only useful when it lands
 * immediately, and this app has no queue worker running.
 */
class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('conversation.'.$this->message->conversation_id)];
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        // The same shape the REST endpoint returns, so the client parses one model.
        $data = (new MessageResource($this->message->load(['sender', 'attachments'])))
            ->response()
            ->getData(true)['data'] ?? [];

        // Rendered inside the sender's request: is_mine would read true for
        // every recipient. Each client decides ownership from sender_id.
        unset($data['is_mine']);

        return $data;
    }
}
