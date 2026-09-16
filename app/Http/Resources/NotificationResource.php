<?php

namespace App\Http\Resources;

use App\Services\NotificationTarget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'type_label' => __('notification_keys.'.$this->type),
            'title' => $this->title,
            'body' => $this->body,
            'ref_id' => $this->ref_id,
            // Deep-link target for record-type notifications (behaviour -> student).
            'student_id' => NotificationTarget::studentIdFor($this->resource),
            'is_read' => $this->is_read,
            'created_at' => $this->created_at,
        ];
    }
}
