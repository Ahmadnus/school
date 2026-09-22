<?php

namespace App\Http\Requests\User;

use App\Enums\Status;
use App\Enums\UserRole;
use App\Rules\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function rules(): array
    {
        $schoolId = $this->user()->school_id;

        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'phone' => [
                'required', 'string', new PhoneNumber,
                Rule::unique('users', 'phone')->where('school_id', $schoolId),
            ],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['nullable', 'required_with:email', 'confirmed', Password::defaults()],
            'role' => ['required', Rule::enum(UserRole::class)],
            'specialty' => ['nullable', 'string', 'max:1000'],
            'status' => ['nullable', Rule::enum(Status::class)],
        ];
    }
}
