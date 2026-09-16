<?php

namespace App\Http\Requests\School;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSchoolRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone_country_code' => ['sometimes', 'required', 'regex:/^[0-9]{1,5}$/'],
            'currency' => ['sometimes', 'required', 'string', 'max:8'],
            'logo' => ['nullable', 'image', 'max:5120'],
            'phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:1000'],
            'website' => ['nullable', 'url', 'max:255'],
            'absence_warning_threshold' => ['nullable', 'integer', 'min:0', 'max:365'],
            'default_pass_score' => ['nullable', 'numeric', 'min:0'],
            'default_max_score' => ['nullable', 'numeric', 'min:1'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $school = $this->user()->school;
            $pass = (float) $this->input('default_pass_score', $school->default_pass_score);
            $max = (float) $this->input('default_max_score', $school->default_max_score);

            if ($pass > $max) {
                $validator->errors()->add('default_pass_score', __('messages.school.pass_above_max'));
            }
        });
    }
}
