<?php

namespace App\Http\Requests\Academic;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGradeRequest extends FormRequest
{
    public function rules(): array
    {
        $grade = $this->route('grade');

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:100',
                Rule::unique('grades', 'name')->where('school_id', $grade->school_id)->ignore($grade->id),
            ],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
