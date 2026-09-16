<?php

namespace App\Http\Requests\Guardian;

use App\Enums\GuardianRelation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGuardianLinkRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'relation' => ['sometimes', 'required', Rule::enum(GuardianRelation::class)],
            'is_primary' => ['nullable', 'boolean'],
        ];
    }
}
