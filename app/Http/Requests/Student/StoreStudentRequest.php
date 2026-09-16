<?php

namespace App\Http\Requests\Student;

use App\Enums\EnrollmentScope;
use App\Enums\Gender;
use App\Enums\Status;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudentRequest extends FormRequest
{
    public function rules(): array
    {
        $schoolId = $this->user()->school_id;

        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'external_id' => [
                'nullable', 'string', 'max:100',
                Rule::unique('students', 'external_id')->where('school_id', $schoolId),
            ],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'nationality' => ['nullable', 'string', 'max:100'],
            'blood_type' => ['nullable', 'string', 'max:8'],
            'address' => ['nullable', 'string', 'max:1000'],
            'building' => ['nullable', 'string', 'max:255'],
            'medical_notes' => ['nullable', 'string', 'max:2000'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:32'],
            'emergency_contact_relation' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(Status::class)],

            // Optional enrollment created alongside the student (the grade/section
            // section of the create form is marked "optional").
            'section_id' => [
                'nullable',
                Rule::exists('sections', 'id')->whereIn(
                    'academic_year_id',
                    \App\Models\AcademicYear::query()->select('id')->where('school_id', $schoolId),
                ),
            ],
            'scope' => ['nullable', 'required_with:section_id', Rule::enum(EnrollmentScope::class)],
            'enrolled_at' => ['nullable', 'required_with:section_id', 'date'],
            'transport_subscribed' => ['nullable', 'boolean'],
        ];
    }
}
