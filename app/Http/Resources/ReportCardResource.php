<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReportCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student_id' => $this->student_id,
            'term_id' => $this->term_id,
            'academic_year_id' => $this->academic_year_id,
            'supervisor_notes' => $this->supervisor_notes,
            // Null while a draft: the UI's "المعدّل: –".
            'average' => $this->average,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'published_at' => $this->published_at,
            'student' => new StudentResource($this->whenLoaded('student')),
            'term' => new TermResource($this->whenLoaded('term')),
            // Frozen rows; empty on a draft, where the sheet is computed instead.
            'lines' => ReportCardLineResource::collection($this->whenLoaded('lines')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
