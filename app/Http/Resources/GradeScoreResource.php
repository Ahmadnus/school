<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GradeScoreResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'assessment_id' => $this->assessment_id,
            'student_id' => $this->student_id,
            'score' => $this->score,
            'rubric_level_id' => $this->rubric_level_id,
            'rubric_level' => new RubricLevelResource($this->whenLoaded('rubricLevel')),
            'entered_at' => $this->entered_at,
            'entered_by' => $this->entered_by,
            'student' => new StudentResource($this->whenLoaded('student')),
        ];
    }
}
