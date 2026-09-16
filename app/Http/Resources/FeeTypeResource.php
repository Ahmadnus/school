<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FeeTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'school_id' => $this->school_id,
            'name' => $this->name,
            'grade_id' => $this->grade_id,
            'subject_id' => $this->subject_id,
            'is_transport' => $this->is_transport,
            'total_amount' => $this->totalAmount()->toDecimal(),
            'notes' => $this->notes,
            'is_default' => $this->is_default,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'grade' => new GradeResource($this->whenLoaded('grade')),
            'subject' => new SubjectResource($this->whenLoaded('subject')),
            'installments' => FeeTypeInstallmentResource::collection($this->whenLoaded('installments')),
            'installments_total' => $this->when(
                $this->relationLoaded('installments'),
                fn () => $this->installmentsTotal()->toDecimal(),
            ),
            'plans_count' => $this->whenCounted('plans'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
