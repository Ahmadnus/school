<?php

namespace App\Http\Requests\Guardian;

use App\Enums\Status;
use App\Rules\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGuardianRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => [
                'required', 'string', new PhoneNumber,
                Rule::unique('guardians', 'phone')->where('school_id', $this->user()->school_id),
            ],
            'email' => ['nullable', 'email', 'max:255'],
            'status' => ['nullable', Rule::enum(Status::class)],
        ];
    }
}
