<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentEnrollmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student_id' => $this->student_id,
            'section_id' => $this->section_id,
            'academic_year_id' => $this->academic_year_id,
            'scope' => $this->scope->value,
            'scope_label' => $this->scope->label(),
            'enrolled_at' => $this->enrolled_at?->toDateString(),
            'transport_subscribed' => $this->transport_subscribed,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            // مواد مختارة — غيابها يعني الخطة الكاملة، وهي الحالة الغالبة.
            'subjects' => SubjectResource::collection($this->whenLoaded('subjects')),
            'section' => new SectionResource($this->whenLoaded('section')),
            'academic_year' => new AcademicYearResource($this->whenLoaded('academicYear')),
            'student' => new StudentResource($this->whenLoaded('student')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
