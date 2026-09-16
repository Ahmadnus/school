<?php

namespace App\Http\Requests\Academic;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAssessmentRequest extends FormRequest
{
    public function rules(): array
    {
        $assessment = $this->route('assessment');
        $schoolId = $this->user()->school_id;

        return [
            'assessment_type_id' => [
                'sometimes', 'required',
                Rule::exists('assessment_types', 'id')->where('school_id', $schoolId),
            ],
            'name' => [
                'sometimes', 'required', 'string', 'max:150',
                Rule::unique('assessments', 'name')
                    ->where('subject_id', $assessment->subject_id)
                    ->ignore($assessment->id),
            ],
            'max_score' => ['nullable', 'numeric', 'min:1', 'max:9999'],
            'weight_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
