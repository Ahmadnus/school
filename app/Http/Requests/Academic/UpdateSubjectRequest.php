<?php

namespace App\Http\Requests\Academic;

use App\Enums\GradingMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSubjectRequest extends FormRequest
{
    public function rules(): array
    {
        $subject = $this->route('subject');

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:150',
                Rule::unique('subjects', 'name')
                    ->where('grade_id', $subject->grade_id)
                    ->where('term_id', $subject->term_id)
                    ->ignore($subject->id),
            ],
            'grading_method' => ['sometimes', Rule::enum(GradingMethod::class)],
            'max_score' => ['nullable', 'numeric', 'min:1', 'max:9999'],
            'pass_score' => ['nullable', 'numeric', 'min:0', 'lte:max_score'],
            'periods_per_week' => ['nullable', 'integer', 'min:0', 'max:50'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'max_score' => $this->input('max_score', $this->route('subject')->max_score),
        ]);
    }
}
