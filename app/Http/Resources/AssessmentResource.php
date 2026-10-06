<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssessmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subject_id' => $this->subject_id,
            'assessment_type_id' => $this->assessment_type_id,
            'name' => $this->name,
            'max_score' => $this->max_score,
            'weight_percent' => $this->weight_percent,
            // The picker on the grade-entry screen shows "<name> (<max score>)".
            'display_name' => $this->name.' ('.$this->max_score.')',
            'type' => new AssessmentTypeResource($this->whenLoaded('type')),
            'subject' => new SubjectResource($this->whenLoaded('subject')),
            // تقييم شعبةٍ أو الصفّ كلّه (`null`)، والصفّ يُقرأ من المادة.
            'section_id' => $this->section_id,
            'section_name' => $this->whenLoaded('section', fn () => $this->section?->name),
            'grade_name' => $this->whenLoaded('subject', fn () => $this->subject?->grade?->name),
            // العلامات لا تصل الأهل قبل النشر؛ الشاشة تعرض زرّ «نشر» حتى يُنشر.
            'published_at' => $this->published_at,
            'is_published' => $this->published_at !== null,
            'scores_count' => $this->whenCounted('scores'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
