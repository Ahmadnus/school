<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GuardianResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'school_id' => $this->school_id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            // Whether the guardian has activated the guardian app.
            'has_account' => $this->user_id !== null,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'students_count' => $this->whenCounted('students'),
            'students' => StudentResource::collection($this->whenLoaded('students')),
            // Present when reached through a student: the link row itself.
            'relation' => $this->whenPivotLoaded('student_guardians', fn () => $this->pivot->relation),
            'relation_label' => $this->whenPivotLoaded(
                'student_guardians',
                fn () => __('guardian_relations.'.$this->pivot->relation),
            ),
            'is_primary' => $this->whenPivotLoaded('student_guardians', fn () => (bool) $this->pivot->is_primary),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
