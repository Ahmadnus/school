<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ما يخرج للوحة. لا علامة ولا معدّل هنا بحال: اللوحة تُعلن تميّزاً، ولا تنشر
 * درجات طالب على أهالي غيره.
 */
class HonorEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $section = $this->student?->currentEnrollment?->section;

        return [
            'id' => $this->id,
            'student_id' => $this->student_id,
            'student_name' => $this->student?->full_name,
            'section_label' => $section
                ? trim(($section->grade?->name ?? '').' - '.$section->name, ' -')
                : null,
            'subject_id' => $this->subject_id,
            'subject_name' => $this->subject?->name,
            'category' => $this->category->value,
            'category_label' => $this->category->label(),
            'reason' => $this->reason,
            'awarded_by' => $this->awarded_by,
            'awarded_by_name' => $this->awarder?->full_name,
            'is_published' => $this->isPublished(),
            'published_at' => $this->published_at,
            'created_at' => $this->created_at,
        ];
    }
}
