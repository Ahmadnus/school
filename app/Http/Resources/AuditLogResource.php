<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'event_label' => __('audit.events.'.$this->event),
            'type' => $this->auditable_type,
            'type_label' => __('audit.models.'.$this->auditable_type),
            'target_id' => $this->auditable_id,
            'label' => $this->label,
            'user' => $this->whenLoaded('user', fn () => $this->user === null ? null : [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'ip' => $this->ip,
            'created_at' => $this->created_at,
        ];
    }
}
