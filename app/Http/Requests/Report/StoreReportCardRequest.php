<?php

namespace App\Http\Requests\Report;

use App\Models\AcademicYear;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReportCardRequest extends FormRequest
{
    public function rules(): array
    {
        $schoolId = $this->user()->school_id;

        return [
            'student_id' => ['required', Rule::exists('students', 'id')->where('school_id', $schoolId)],
            'term_id' => [
                'required',
                Rule::exists('terms', 'id')->whereIn(
                    'academic_year_id',
                    AcademicYear::query()->select('id')->where('school_id', $schoolId),
                ),
                // One card per student per term.
                Rule::unique('report_cards', 'term_id')->where('student_id', $this->input('student_id')),
            ],
            'supervisor_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return ['term_id.unique' => __('messages.report_card.duplicate')];
    }
}
