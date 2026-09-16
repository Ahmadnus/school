<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'grade_id' => $this->grade_id,
            'academic_year_id' => $this->academic_year_id,
            'name' => $this->name,
            'capacity' => $this->capacity,
            'sort_order' => $this->sort_order,
            // Head count is always the current-year enrollment count, never a column.
            'students_count' => $this->whenCounted('enrollments'),
            'grade' => new GradeResource($this->whenLoaded('grade')),
            'academic_year' => new AcademicYearResource($this->whenLoaded('academicYear')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
