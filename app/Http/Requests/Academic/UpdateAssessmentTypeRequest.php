<?php

namespace App\Http\Requests\Academic;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAssessmentTypeRequest extends FormRequest
{
    public function rules(): array
    {
        $type = $this->route('assessment_type');

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:150',
                Rule::unique('assessment_types', 'name')
                    ->where('school_id', $type->school_id)
                    ->ignore($type->id),
            ],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_default' => ['nullable', 'boolean'],
            'is_exam' => ['nullable', 'boolean'],
            // حصّة النوع من المئة: «الكويزات ٢٠» تُكتب مرّة لا مع كل ورقة.
            'weight_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
