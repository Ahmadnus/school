<?php

namespace App\Http\Requests\Academic;

use App\Models\Grade;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssessmentRequest extends FormRequest
{
    public function rules(): array
    {
        $schoolId = $this->user()->school_id;

        return [
            'subject_id' => [
                'required',
                Rule::exists('subjects', 'id')->whereIn(
                    'grade_id',
                    Grade::query()->select('id')->where('school_id', $schoolId),
                ),
            ],
            'assessment_type_id' => [
                'required',
                Rule::exists('assessment_types', 'id')->where('school_id', $schoolId),
            ],
            'name' => ['required', 'string', 'max:150'],
            'max_score' => ['nullable', 'numeric', 'min:1', 'max:9999'],
            'weight_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->sometimes('name', [
            Rule::unique('assessments', 'name')->where('subject_id', $this->input('subject_id')),
        ], fn () => $this->filled('subject_id'));
    }
}
