<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExcuseRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'student_id' => [
                'required',
                Rule::exists('students', 'id')->where('school_id', $this->user()->school_id),
            ],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
