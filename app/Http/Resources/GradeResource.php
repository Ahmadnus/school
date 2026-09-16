<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GradeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'school_id' => $this->school_id,
            'name' => $this->name,
            'sort_order' => $this->sort_order,
            'sections' => SectionResource::collection($this->whenLoaded('sections')),
            'sections_count' => $this->whenCounted('sections'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
