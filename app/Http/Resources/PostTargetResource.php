<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PostTargetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'scope' => $this->scope->value,
            'scope_label' => $this->scope->label(),
            'target_id' => $this->target_id,
            // Ready-made chip text, e.g. "شعبة: علمي - إناث".
            'label' => $this->describe(),
        ];
    }
}
