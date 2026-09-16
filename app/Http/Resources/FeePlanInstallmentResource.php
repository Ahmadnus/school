<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FeePlanInstallmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $state = $this->state();

        return [
            'id' => $this->id,
            'fee_plan_id' => $this->fee_plan_id,
            'sort_order' => $this->sort_order,
            'due_date' => $this->due_date?->toDateString(),
            'amount' => $this->amount()->toDecimal(),
            'paid_amount' => $this->paidAmount()->toDecimal(),
            'remaining_amount' => $this->remainingAmount()->toDecimal(),
            'state' => $state->value,
            'state_label' => $state->label(),
            'is_overdue' => $this->isOverdue(),
            'notes' => $this->notes,
        ];
    }
}
