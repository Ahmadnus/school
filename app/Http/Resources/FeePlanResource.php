<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FeePlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $overdueSince = $this->relationLoaded('installments') ? $this->overdueSince() : null;

        return [
            'id' => $this->id,
            'student_id' => $this->student_id,
            'academic_year_id' => $this->academic_year_id,
            'fee_type_id' => $this->fee_type_id,
            'total_amount' => $this->totalAmount()->toDecimal(),
            'discount_amount' => $this->discountAmount()->toDecimal(),
            'discount_reason' => $this->discount_reason,
            'has_discount' => $this->hasDiscount(),
            'net_amount' => $this->netAmount()->toDecimal(),
            'paid_amount' => $this->paidAmount()->toDecimal(),
            'remaining_amount' => $this->remainingAmount()->toDecimal(),
            // يجب أن يبقى صفراً؛ ظهوره موجباً إنذار لا تحذير.
            'overpaid_amount' => $this->overpaidAmount()->toDecimal(),
            'payment_state' => $this->paymentState()->value,
            'payment_state_label' => $this->paymentState()->label(),
            'is_overdue' => $overdueSince !== null,
            'overdue_since' => $overdueSince?->toDateString(),
            'notes' => $this->notes,
            'payment_reminders' => $this->payment_reminders,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'student' => new StudentResource($this->whenLoaded('student')),
            'academic_year' => new AcademicYearResource($this->whenLoaded('academicYear')),
            // دائماً نسخة الخطة، لا صفوف النوع (قرار 2-أ).
            'installments' => FeePlanInstallmentResource::collection($this->whenLoaded('installments')),
            'payments' => FeePaymentResource::collection($this->whenLoaded('payments')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
