<?php

namespace App\Http\Resources;

use App\Enums\UserRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PostTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'group' => $this->group->value,
            'group_label' => $this->group->label(),
            'sort_order' => $this->sort_order,
            'is_enabled' => $this->is_enabled,
            'min_role' => $this->min_role->value,
            'requires_approval' => $this->requires_approval,
            // The Primary-coloured result line under the permission chips.
            'allowed_roles' => array_map(
                fn (UserRole $role) => ['value' => $role->value, 'label' => $role->label()],
                $this->allowedRoles(),
            ),
            'can_create' => $request->user() ? $this->allows($request->user()) : false,
            'posts_count' => $this->whenCounted('posts'),
        ];
    }
}
