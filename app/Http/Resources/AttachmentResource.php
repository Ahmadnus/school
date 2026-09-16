<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'owner_type' => $this->owner_type->value,
            'owner_id' => $this->owner_id,
            'name' => $this->name,
            'mime' => $this->mime,
            'size' => $this->size,
            'url' => $this->url,
            'is_image' => $this->isImage(),
            'uploaded_by' => $this->uploaded_by,
            'created_at' => $this->created_at,
        ];
    }
}
