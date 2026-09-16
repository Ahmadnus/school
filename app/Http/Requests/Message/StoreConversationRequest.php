<?php

namespace App\Http\Requests\Message;

use App\Enums\ConversationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreConversationRequest extends FormRequest
{
    public function rules(): array
    {
        $schoolId = $this->user()->school_id;

        return [
            'type' => ['required', Rule::enum(ConversationType::class)],
            // Guardian threads are always about one student.
            'student_id' => [
                'required_if:type,guardians', 'nullable',
                Rule::exists('students', 'id')->where('school_id', $schoolId),
            ],
            'title' => ['nullable', 'string', 'max:255'],
            'participant_ids' => ['nullable', 'array'],
            'participant_ids.*' => [Rule::exists('users', 'id')->where('school_id', $schoolId)],
            'body' => ['required', 'string', 'max:10000'],
        ];
    }
}
