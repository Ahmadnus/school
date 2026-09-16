<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'group' => $this->group,
            'group_label' => __('notification_groups.'.$this->group),
            'is_enabled' => $this->is_enabled,
            'app' => $this->when(isset($this->app), fn () => $this->app->value),
        ];
    }
}
