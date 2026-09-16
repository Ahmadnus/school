<?php

namespace App\Http\Requests\Academic;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTermRequest extends FormRequest
{
    public function rules(): array
    {
        $term = $this->route('term');

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:100',
                Rule::unique('terms', 'name')
                    ->where('academic_year_id', $term->academic_year_id)
                    ->ignore($term->id),
            ],
            'start_date' => ['sometimes', 'required', 'date'],
            'end_date' => ['sometimes', 'required', 'date', 'after:start_date'],
            'is_current' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'start_date' => $this->input('start_date', $this->route('term')->start_date?->toDateString()),
        ]);
    }
}
