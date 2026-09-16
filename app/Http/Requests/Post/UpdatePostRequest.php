<?php

namespace App\Http\Requests\Post;

use App\Enums\TargetScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePostRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:10000'],
            'subject_id' => ['nullable', 'integer'],
            'requires_confirmation' => ['sometimes', 'boolean'],
            'targets' => ['nullable', 'array', 'min:1'],
            'targets.*.scope' => ['required', Rule::enum(TargetScope::class)],
            'targets.*.target_id' => ['nullable', 'integer'],
        ];
    }
}
