<?php

namespace App\Http\Requests\Student;

use App\Enums\Gender;
use App\Enums\Status;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentRequest extends FormRequest
{
    public function rules(): array
    {
        $student = $this->route('student');

        return [
            'first_name' => ['sometimes', 'required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'external_id' => [
                'nullable', 'string', 'max:100',
                Rule::unique('students', 'external_id')
                    ->where('school_id', $student->school_id)
                    ->ignore($student->id),
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
            'status' => ['sometimes', Rule::enum(Status::class)],
        ];
    }
}
