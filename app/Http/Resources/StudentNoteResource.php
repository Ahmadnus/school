<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentNoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student_id' => $this->student_id,
            'author_id' => $this->author_id,
            'author_name' => $this->author?->full_name,
            'body' => $this->body,
            // Lets the client show edit/delete only where the policy will allow it.
            'can_manage' => $request->user()?->can('update', $this->resource) ?? false,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
