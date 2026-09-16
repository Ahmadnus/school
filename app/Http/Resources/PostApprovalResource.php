<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PostApprovalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'post_id' => $this->post_id,
            'reviewer_id' => $this->reviewer_id,
            'decision' => $this->decision,
            'note' => $this->note,
            'decided_at' => $this->decided_at,
            'reviewer' => new UserResource($this->whenLoaded('reviewer')),
        ];
    }
}
