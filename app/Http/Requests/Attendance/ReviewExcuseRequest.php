<?php

namespace App\Http\Requests\Attendance;

use App\Enums\ExcuseStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewExcuseRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status' => [
                'required',
                Rule::enum(ExcuseStatus::class)->only([ExcuseStatus::Accepted, ExcuseStatus::Rejected]),
            ],
            'review_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
