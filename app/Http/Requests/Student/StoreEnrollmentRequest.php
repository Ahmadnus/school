<?php

namespace App\Http\Requests\Student;

use App\Enums\EnrollmentScope;
use App\Enums\EnrollmentStatus;
use App\Models\AcademicYear;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEnrollmentRequest extends FormRequest
{
    public function rules(): array
    {
        $schoolId = $this->user()->school_id;
        $student = $this->route('student');

        return [
            'section_id' => [
                'required',
                Rule::exists('sections', 'id')->whereIn(
                    'academic_year_id',
                    AcademicYear::query()->select('id')->where('school_id', $schoolId),
                ),
            ],
            'academic_year_id' => [
                'required',
                Rule::exists('academic_years', 'id')->where('school_id', $schoolId),
                Rule::unique('student_enrollments', 'academic_year_id')
                    ->where('student_id', $student->id),
            ],
            'scope' => ['required', Rule::enum(EnrollmentScope::class)],
            'enrolled_at' => ['required', 'date'],
            'transport_subscribed' => ['nullable', 'boolean'],
            'status' => ['nullable', Rule::enum(EnrollmentStatus::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('academic_year_id')) {
            $this->merge([
                'academic_year_id' => AcademicYear::currentFor($this->user()->school_id)?->id,
            ]);
        }
    }

    public function messages(): array
    {
        return ['academic_year_id.unique' => __('messages.enrollment.duplicate_year')];
    }
}
