<?php

namespace App\Http\Requests\Academic;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSectionRequest extends FormRequest
{
    public function rules(): array
    {
        $section = $this->route('section');

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:100',
                Rule::unique('sections', 'name')
                    ->where('grade_id', $section->grade_id)
                    ->where('academic_year_id', $section->academic_year_id)
                    ->ignore($section->id),
            ],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
