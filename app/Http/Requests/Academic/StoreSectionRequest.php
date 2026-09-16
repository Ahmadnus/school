<?php

namespace App\Http\Requests\Academic;

use App\Models\AcademicYear;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSectionRequest extends FormRequest
{
    public function rules(): array
    {
        $schoolId = $this->user()->school_id;

        return [
            'grade_id' => [
                'required',
                Rule::exists('grades', 'id')->where('school_id', $schoolId),
            ],
            'academic_year_id' => [
                'required',
                Rule::exists('academic_years', 'id')->where('school_id', $schoolId),
            ],
            'name' => ['required', 'string', 'max:100'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /** The section form has no year field — it defaults to the active year. */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('academic_year_id')) {
            $this->merge([
                'academic_year_id' => AcademicYear::currentFor($this->user()->school_id)?->id,
            ]);
        }
    }

    public function withValidator($validator): void
    {
        $validator->sometimes('name', [
            Rule::unique('sections', 'name')
                ->where('grade_id', $this->input('grade_id'))
                ->where('academic_year_id', $this->input('academic_year_id')),
        ], fn () => $this->filled(['grade_id', 'academic_year_id']));
    }
}
