<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RubricLevelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rubric_id' => $this->rubric_id,
            'name' => $this->name,
            'value' => $this->value,
            'sort_order' => $this->sort_order,
        ];
    }
}
