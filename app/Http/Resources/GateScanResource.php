<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GateScanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nfc_uid' => $this->nfc_uid,
            'student_id' => $this->student_id,
            'scanned_at' => $this->scanned_at,
            'result' => $this->result->value,
            'result_label' => $this->result->label(),
            'student' => new StudentResource($this->whenLoaded('student')),
        ];
    }
}
