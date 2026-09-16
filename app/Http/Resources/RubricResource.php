<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RubricResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'school_id' => $this->school_id,
            'name' => $this->name,
            'is_default' => $this->is_default,
            'levels' => RubricLevelResource::collection($this->whenLoaded('levels')),
            'levels_count' => $this->whenCounted('levels'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
