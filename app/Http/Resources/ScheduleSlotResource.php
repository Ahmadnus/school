<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScheduleSlotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'section_id' => $this->section_id,
            'term_id' => $this->term_id,
            'subject_id' => $this->subject_id,
            'staff_id' => $this->staff_id,
            'day_of_week' => $this->day_of_week->value,
            'day_label' => $this->day_of_week->label(),
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'period_number' => $this->period_number,
            'room' => $this->room,
            'subject' => new SubjectResource($this->whenLoaded('subject')),
            'teacher' => new UserResource($this->whenLoaded('teacher')),
            'section' => new SectionResource($this->whenLoaded('section')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
