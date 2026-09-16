<?php

namespace App\Http\Resources;

use App\Models\StudentImport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentImportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'academic_year_id' => $this->academic_year_id,
            'section_id' => $this->section_id,
            'original_name' => $this->original_name,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'headers' => $this->headers,
            'mapping' => $this->mapping,
            'rows_count' => $this->rows_count,
            'valid_count' => $this->valid_count,
            'invalid_count' => $this->invalid_count,
            'imported_count' => $this->imported_count,
            'error' => $this->error,
            // What the mapping screen offers on the system side.
            'fields' => collect(StudentImport::FIELDS)->map(fn (bool $required, string $key) => [
                'key' => $key,
                'label' => __('import_fields.'.$key),
                'required' => $required,
            ])->values(),
            'section' => new SectionResource($this->whenLoaded('section')),
            'academic_year' => new AcademicYearResource($this->whenLoaded('academicYear')),
            'created_at' => $this->created_at,
        ];
    }
}
