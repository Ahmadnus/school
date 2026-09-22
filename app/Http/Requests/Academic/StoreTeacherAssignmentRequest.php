<?php

namespace App\Http\Requests\Academic;

use App\Enums\UserRole;
use App\Models\AcademicYear;
use App\Models\Grade;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTeacherAssignmentRequest extends FormRequest
{
    public function rules(): array
    {
        $schoolId = $this->user()->school_id;

        return [
            'staff_id' => [
                'required',
                Rule::exists('users', 'id')
                    ->where('school_id', $schoolId)
                    ->whereIn('role', [UserRole::Teacher->value, UserRole::Admin->value, UserRole::SuperAdmin->value]),
            ],
            'subject_id' => [
                'required',
                Rule::exists('subjects', 'id')->whereIn(
                    'grade_id',
                    Grade::query()->select('id')->where('school_id', $schoolId),
                ),
            ],
            'section_id' => [
                'required',
                Rule::exists('sections', 'id')->whereIn(
                    'academic_year_id',
                    AcademicYear::query()->select('id')->where('school_id', $schoolId),
                ),
                Rule::unique('teacher_assignments', 'section_id')
                    ->where('staff_id', $this->input('staff_id'))
                    ->where('subject_id', $this->input('subject_id')),
            ],
        ];
    }
}
