<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SchoolResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'website' => $this->website,
            'logo_path' => $this->logo_path,
            'logo_url' => $this->logo_path ? asset('storage/'.$this->logo_path) : null,
            'currency' => $this->currency,
            'phone_country_code' => $this->phone_country_code,
            'absence_warning_threshold' => $this->absence_warning_threshold,
            'default_pass_score' => $this->default_pass_score,
            'default_max_score' => $this->default_max_score,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
