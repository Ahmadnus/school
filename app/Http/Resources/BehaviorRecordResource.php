<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BehaviorRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student_id' => $this->student_id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'category' => $this->category,
            'title' => $this->title,
            'description' => $this->description,
            'occurred_on' => $this->occurred_on?->toDateString(),
            'action_taken' => $this->action_taken,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'visible_to_guardian' => $this->visible_to_guardian,
            'recorded_by' => $this->recorded_by,
            'recorded_by_name' => $this->recorder?->full_name,
            'can_manage' => $request->user()?->can('update', $this->resource) ?? false,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
