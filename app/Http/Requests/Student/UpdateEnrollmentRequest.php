<?php

namespace App\Http\Requests\Student;

use App\Enums\EnrollmentScope;
use App\Enums\EnrollmentStatus;
use App\Models\AcademicYear;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEnrollmentRequest extends FormRequest
{
    public function rules(): array
    {
        $schoolId = $this->user()->school_id;

        return [
            // The year is fixed; moving a student means changing the section.
            'section_id' => [
                'sometimes', 'required',
                Rule::exists('sections', 'id')->whereIn(
                    'academic_year_id',
                    AcademicYear::query()->select('id')->where('school_id', $schoolId),
                ),
            ],
            'scope' => ['sometimes', 'required', Rule::enum(EnrollmentScope::class)],
            'enrolled_at' => ['sometimes', 'required', 'date'],
            'transport_subscribed' => ['nullable', 'boolean'],
            'status' => ['sometimes', Rule::enum(EnrollmentStatus::class)],
        ];
    }
}
