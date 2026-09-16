<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FeeTypeInstallmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fee_type_id' => $this->fee_type_id,
            'sort_order' => $this->sort_order,
            'due_date' => $this->due_date?->toDateString(),
            'amount' => $this->amount()->toDecimal(),
            'notes' => $this->notes,
        ];
    }
}
