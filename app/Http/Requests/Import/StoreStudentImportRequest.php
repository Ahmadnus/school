<?php

namespace App\Http\Requests\Import;

use App\Models\AcademicYear;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The section and year are chosen before the file is picked, because every
 * imported student is enrolled into them.
 */
class StoreStudentImportRequest extends FormRequest
{
    public function rules(): array
    {
        $schoolId = $this->user()->school_id;

        return [
            'academic_year_id' => [
                'required',
                Rule::exists('academic_years', 'id')->where('school_id', $schoolId),
            ],
            'section_id' => [
                'required',
                Rule::exists('sections', 'id')->whereIn(
                    'academic_year_id',
                    AcademicYear::query()->select('id')->where('school_id', $schoolId),
                ),
            ],
            // .xls is explicitly unsupported; the cap is 10 MB.
            'file' => ['required', 'file', 'max:10240', 'mimes:xlsx,csv,txt'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('academic_year_id')) {
            $this->merge([
                'academic_year_id' => AcademicYear::currentFor($this->user()->school_id)?->id,
            ]);
        }
    }

    public function messages(): array
    {
        return ['file.mimes' => __('messages.import.unsupported_format')];
    }
}
