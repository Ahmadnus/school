<?php

namespace App\Http\Requests\Academic;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRubricRequest extends FormRequest
{
    public function rules(): array
    {
        $rubric = $this->route('rubric');

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:150',
                Rule::unique('rubrics', 'name')->where('school_id', $rubric->school_id)->ignore($rubric->id),
            ],
            'is_default' => ['nullable', 'boolean'],

            // When sent, the level list replaces the existing one wholesale.
            'levels' => ['nullable', 'array'],
            'levels.*.name' => ['required', 'string', 'max:150'],
            'levels.*.value' => ['nullable', 'numeric'],
        ];
    }
}
