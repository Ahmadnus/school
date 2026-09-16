<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PostReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user_name' => $this->user?->full_name,
            'role' => $this->user?->role?->value,
            'role_label' => $this->user?->role?->label(),
            'read_at' => $this->read_at,
            'confirmed_at' => $this->confirmed_at,
        ];
    }
}
