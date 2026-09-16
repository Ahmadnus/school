<?php

namespace App\Http\Requests\Student;

use App\Enums\EnrollmentScope;
use App\Models\AcademicYear;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkEnrollRequest extends FormRequest
{
    public function rules(): array
    {
        $schoolId = $this->user()->school_id;

        return [
            'student_ids' => ['required', 'array', 'min:1', 'max:500'],
            'student_ids.*' => ['integer', Rule::exists('students', 'id')->where('school_id', $schoolId)],
            'section_id' => [
                'required',
                Rule::exists('sections', 'id')->whereIn(
                    'academic_year_id',
                    AcademicYear::query()->select('id')->where('school_id', $schoolId),
                ),
            ],
            'scope' => ['nullable', Rule::enum(EnrollmentScope::class)],
            'enrolled_at' => ['nullable', 'date'],
            'transport_subscribed' => ['nullable', 'boolean'],
        ];
    }
}
