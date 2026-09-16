<?php

namespace App\Http\Requests\Academic;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRubricRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:150',
                Rule::unique('rubrics', 'name')->where('school_id', $this->user()->school_id),
            ],
            'is_default' => ['nullable', 'boolean'],

            // The missing "define rubric levels" screen: levels can be sent with
            // the rubric itself (decision 5-a).
            'levels' => ['nullable', 'array'],
            'levels.*.name' => ['required', 'string', 'max:150'],
            'levels.*.value' => ['nullable', 'numeric'],
        ];
    }
}
