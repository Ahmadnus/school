<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The "link an NFC card to a student" form the spec lists as missing (#29). */
class StoreStudentCardRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'nfc_uid' => ['required', 'string', 'max:100', Rule::unique('student_cards', 'nfc_uid')],
        ];
    }
}
