<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student_id' => $this->student_id,
            'nfc_uid' => $this->nfc_uid,
            'issued_at' => $this->issued_at,
            'revoked_at' => $this->revoked_at,
            'is_active' => $this->is_active,
            'student' => new StudentResource($this->whenLoaded('student')),
        ];
    }
}
