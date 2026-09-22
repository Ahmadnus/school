<?php

namespace App\Http\Resources;

use App\Enums\ExcuseStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // The stored status stays three-valued; "excused" is derived by
        // App\Services\AttendanceSummary and returned alongside the list.
        return [
            'id' => $this->id,
            'student_id' => $this->student_id,
            'section_id' => $this->section_id,
            'date' => $this->date?->toDateString(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'source' => $this->source->value,
            'source_label' => $this->source->label(),
            'recorded_by' => $this->recorded_by,
            'recorded_by_name' => $this->whenLoaded('recorder', fn () => $this->recorder?->full_name),
            'recorded_at' => $this->recorded_at,
            // Set by AttendanceController::index for the page in one query.
            'is_excused' => $this->when(
                array_key_exists('excuse_match', $this->getAttributes()),
                fn () => $this->excuse_match?->status === ExcuseStatus::Accepted,
            ),
            'excuse' => $this->when(
                array_key_exists('excuse_match', $this->getAttributes()),
                fn () => $this->excuse_match ? [
                    'id' => $this->excuse_match->id,
                    'status' => $this->excuse_match->status->value,
                    'status_label' => $this->excuse_match->status->label(),
                    'reason' => $this->excuse_match->reason,
                ] : null,
            ),
            'student' => new StudentResource($this->whenLoaded('student')),
            'section' => new SectionResource($this->whenLoaded('section')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
