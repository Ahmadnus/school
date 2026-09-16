<?php

namespace App\Http\Requests\Report;

use App\Models\AcademicYear;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The add/edit holiday form the spec lists as missing (#12). */
class StoreHolidayRequest extends FormRequest
{
    public function rules(): array
    {
        $schoolId = $this->user()->school_id;

        return [
            'name' => ['required', 'string', 'max:150'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'academic_year_id' => [
                'nullable',
                Rule::exists('academic_years', 'id')->where('school_id', $schoolId),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('academic_year_id')) {
            $this->merge([
                'academic_year_id' => AcademicYear::currentFor($this->user()->school_id)?->id,
            ]);
        }
    }
}
