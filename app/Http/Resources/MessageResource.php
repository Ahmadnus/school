<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'sender_id' => $this->sender_id,
            'body' => $this->body,
            'sent_at' => $this->sent_at,
            'is_mine' => $request->user()?->id === $this->sender_id,
            'sender' => new UserResource($this->whenLoaded('sender')),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
        ];
    }
}
