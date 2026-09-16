<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'section_id' => $this->section_id,
            'date' => $this->date?->toDateString(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'submitted_by' => $this->submitted_by,
            'submitted_at' => $this->submitted_at,
        ];
    }
}
