<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FeePaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fee_plan_id' => $this->fee_plan_id,
            'receipt_number' => $this->receipt_number,
            'paid_on' => $this->paid_on?->toDateString(),
            'amount' => $this->amount()->toDecimal(),
            'method' => $this->method->value,
            'method_label' => $this->method->label(),
            'description' => $this->description,
            'reference' => $this->reference,
            'recorded_by' => $this->recorded_by,
            'recorder_name' => $this->whenLoaded('recorder', fn () => $this->recorder?->name),
            'is_voided' => $this->isVoided(),
            'voided_at' => $this->voided_at,
            'void_reason' => $this->void_reason,
            'allocations' => $this->whenLoaded('allocations', fn () => $this->allocations->map(fn ($a) => [
                'installment_id' => $a->fee_plan_installment_id,
                'amount' => $a->amount()->toDecimal(),
            ])),
            'created_at' => $this->created_at,
        ];
    }
}
