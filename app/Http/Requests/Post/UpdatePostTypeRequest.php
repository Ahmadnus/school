<?php

namespace App\Http\Requests\Post;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The types screen only toggles availability and the minimum creating role. */
class UpdatePostTypeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'is_enabled' => ['nullable', 'boolean'],
            'requires_approval' => ['nullable', 'boolean'],
            'min_role' => [
                'sometimes', 'required',
                Rule::enum(UserRole::class)->only([
                    UserRole::Teacher, UserRole::Admin, UserRole::SuperAdmin,
                ]),
            ],
        ];
    }
}
