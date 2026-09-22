<?php

namespace App\Http\Requests\Guardian;

use App\Enums\GuardianRelation;
use App\Rules\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Links a guardian to a student. Supports both modes of the student form:
 * "link an existing guardian" (guardian_id) and "create a new one" (name + phone).
 */
class AttachGuardianRequest extends FormRequest
{
    public function rules(): array
    {
        $schoolId = $this->user()->school_id;
        $student = $this->route('student');

        return [
            'guardian_id' => [
                'required_without:name',
                'nullable',
                Rule::exists('guardians', 'id')->where('school_id', $schoolId),
                Rule::unique('student_guardians', 'guardian_id')->where('student_id', $student->id),
            ],
            'name' => ['required_without:guardian_id', 'nullable', 'string', 'max:255'],
            'phone' => [
                'required_with:name', 'nullable', 'string', new PhoneNumber,
                Rule::unique('guardians', 'phone')->where('school_id', $schoolId),
            ],
            'email' => ['nullable', 'email', 'max:255'],
            'relation' => ['required', Rule::enum(GuardianRelation::class)],
            'is_primary' => ['nullable', 'boolean'],
        ];
    }
}
