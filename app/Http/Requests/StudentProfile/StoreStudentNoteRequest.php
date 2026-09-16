<?php

namespace App\Http\Requests\StudentProfile;

use Illuminate\Foundation\Http\FormRequest;

class StoreStudentNoteRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
        ];
    }
}
