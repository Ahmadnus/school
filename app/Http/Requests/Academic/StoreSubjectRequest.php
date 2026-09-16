<?php

namespace App\Http\Requests\Academic;

use App\Enums\GradingMethod;
use App\Models\AcademicYear;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSubjectRequest extends FormRequest
{
    public function rules(): array
    {
        $schoolId = $this->user()->school_id;

        return [
            'grade_id' => ['required', Rule::exists('grades', 'id')->where('school_id', $schoolId)],
            'term_id' => [
                'required',
                Rule::exists('terms', 'id')->whereIn(
                    'academic_year_id',
                    AcademicYear::query()->select('id')->where('school_id', $schoolId),
                ),
            ],
            'name' => ['required', 'string', 'max:150'],
            'grading_method' => ['nullable', Rule::enum(GradingMethod::class)],
            'max_score' => ['nullable', 'numeric', 'min:1', 'max:9999'],
            'pass_score' => ['nullable', 'numeric', 'min:0', 'lte:max_score'],
            'periods_per_week' => ['nullable', 'integer', 'min:0', 'max:50'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // "lte:max_score" needs the effective ceiling, not just what was posted.
        $school = $this->user()->school;
        $this->merge([
            'max_score' => $this->input('max_score', $school->default_max_score ?? 100),
            'pass_score' => $this->input('pass_score', $school->default_pass_score ?? 50),
        ]);
    }

    public function withValidator($validator): void
    {
        $validator->sometimes('name', [
            Rule::unique('subjects', 'name')
                ->where('grade_id', $this->input('grade_id'))
                ->where('term_id', $this->input('term_id')),
        ], fn () => $this->filled(['grade_id', 'term_id']));
    }
}
