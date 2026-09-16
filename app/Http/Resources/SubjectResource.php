<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'grade_id' => $this->grade_id,
            'term_id' => $this->term_id,
            'name' => $this->name,
            'grading_method' => $this->grading_method->value,
            'grading_method_label' => $this->grading_method->label(),
            'max_score' => $this->max_score,
            'pass_score' => $this->pass_score,
            'periods_per_week' => $this->periods_per_week,
            'grade' => new GradeResource($this->whenLoaded('grade')),
            'term' => new TermResource($this->whenLoaded('term')),
            'teachers' => UserResource::collection($this->whenLoaded('teachers')),
            'assignments_count' => $this->whenCounted('assignments'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
