<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeacherAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'staff_id' => $this->staff_id,
            'subject_id' => $this->subject_id,
            'section_id' => $this->section_id,
            'teacher' => new UserResource($this->whenLoaded('teacher')),
            'subject' => new SubjectResource($this->whenLoaded('subject')),
            'section' => new SectionResource($this->whenLoaded('section')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'lessons_per_week' => $this->lessonsPerWeek(),
            // هل الرقم مكتوب على الإسناد نفسه، أم موروث من المادة؟ الشاشة
            // تعرض الفرق حتى يعرف المدير ما ضُبط فعلاً وما هو افتراض.
            'lessons_per_week_explicit' => $this->lessons_per_week !== null,
        ];
    }
}
