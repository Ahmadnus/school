<?php

namespace App\Http\Requests\Guardian;

use App\Enums\Status;
use App\Rules\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGuardianRequest extends FormRequest
{
    public function rules(): array
    {
        $guardian = $this->route('guardian');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => [
                'sometimes', 'required', 'string', new PhoneNumber,
                Rule::unique('guardians', 'phone')
                    ->where('school_id', $guardian->school_id)
                    ->ignore($guardian->id),
            ],
            'email' => ['nullable', 'email', 'max:255'],
            'status' => ['sometimes', Rule::enum(Status::class)],
        ];
    }
}
