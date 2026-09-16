<?php

namespace App\Http\Requests\Message;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFollowUpRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'needs_follow_up' => ['sometimes', 'boolean'],
            'follow_up_at' => ['nullable', 'date'],
            'is_important' => ['sometimes', 'boolean'],
            'follow_up_note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
