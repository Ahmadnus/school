<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentImportRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'row_number' => $this->row_number,
            'raw' => $this->raw,
            'mapped' => $this->mapped,
            'errors' => $this->errors,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'student_id' => $this->student_id,
        ];
    }
}
