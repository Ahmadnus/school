<?php

namespace App\Http\Requests\Academic;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGradeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('grades', 'name')->where('school_id', $this->user()->school_id),
            ],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
