<?php

namespace App\Http\Requests\Academic;

use App\Enums\Weekday;
use App\Models\AcademicYear;
use App\Models\Grade;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreScheduleSlotRequest extends FormRequest
{
    public function rules(): array
    {
        $schoolId = $this->user()->school_id;

        return [
            'section_id' => [
                'required',
                Rule::exists('sections', 'id')->whereIn(
                    'academic_year_id',
                    AcademicYear::query()->select('id')->where('school_id', $schoolId),
                ),
            ],
            'term_id' => [
                'required',
                Rule::exists('terms', 'id')->whereIn(
                    'academic_year_id',
                    AcademicYear::query()->select('id')->where('school_id', $schoolId),
                ),
            ],
            'subject_id' => [
                'required',
                Rule::exists('subjects', 'id')->whereIn(
                    'grade_id',
                    Grade::query()->select('id')->where('school_id', $schoolId),
                ),
            ],
            // The teacher field is explicitly optional on the form.
            'staff_id' => ['nullable', Rule::exists('users', 'id')->where('school_id', $schoolId)],
            'day_of_week' => ['required', Rule::enum(Weekday::class)],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
            'period_number' => [
                'required', 'integer', 'min:1', 'max:20',
                Rule::unique('schedule_slots', 'period_number')
                    ->where('section_id', $this->input('section_id'))
                    ->where('term_id', $this->input('term_id'))
                    ->where('day_of_week', $this->input('day_of_week')),
            ],
            'room' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return ['period_number.unique' => __('messages.slot.period_taken')];
    }
}
