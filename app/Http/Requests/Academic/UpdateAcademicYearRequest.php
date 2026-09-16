<?php

namespace App\Http\Requests\Academic;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAcademicYearRequest extends FormRequest
{
    public function rules(): array
    {
        $year = $this->route('academic_year');

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:100',
                Rule::unique('academic_years', 'name')
                    ->where('school_id', $year->school_id)
                    ->ignore($year->id),
            ],
            'start_date' => ['sometimes', 'required', 'date'],
            'end_date' => ['sometimes', 'required', 'date', 'after:start_date'],
            'is_current' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // "after:start_date" needs a start date in the payload even on a partial update.
        $this->merge([
            'start_date' => $this->input('start_date', $this->route('academic_year')->start_date?->toDateString()),
        ]);
    }
}
