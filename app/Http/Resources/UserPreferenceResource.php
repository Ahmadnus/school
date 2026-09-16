<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserPreferenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'user_id' => $this->user_id,
            'locale' => $this->locale,
            'theme' => $this->theme,
            'attachment_save' => $this->attachment_save,
        ];
    }
}
