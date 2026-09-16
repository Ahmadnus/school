<?php

namespace App\Http\Requests\StudentProfile;

use App\Enums\BehaviorStatus;
use App\Enums\BehaviorType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBehaviorRecordRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'required', Rule::enum(BehaviorType::class)],
            'category' => ['nullable', 'string', 'max:100'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'occurred_on' => ['sometimes', 'required', 'date', 'before_or_equal:today'],
            'action_taken' => ['nullable', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::enum(BehaviorStatus::class)],
            'visible_to_guardian' => ['sometimes', 'boolean'],
        ];
    }
}
