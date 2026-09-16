<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReportCardLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subject_id' => $this->subject_id,
            'score' => $this->score,
            'grade_label' => $this->grade_label,
            'sort_order' => $this->sort_order,
            'subject' => new SubjectResource($this->whenLoaded('subject')),
        ];
    }
}
