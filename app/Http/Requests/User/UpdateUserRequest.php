<?php

namespace App\Http\Requests\User;

use App\Enums\Status;
use App\Enums\UserRole;
use App\Rules\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'first_name' => ['sometimes', 'required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'phone' => [
                'sometimes', 'required', 'string', new PhoneNumber,
                Rule::unique('users', 'phone')->where('school_id', $user->school_id)->ignore($user->id),
            ],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'role' => ['sometimes', 'required', Rule::enum(UserRole::class)],
            'specialty' => ['nullable', 'string', 'max:1000'],
            'status' => ['sometimes', Rule::enum(Status::class)],
        ];
    }
}
