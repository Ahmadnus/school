<?php

namespace App\Http\Requests\Academic;

use App\Enums\Weekday;
use App\Models\Grade;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateScheduleSlotRequest extends FormRequest
{
    public function rules(): array
    {
        $slot = $this->route('slot');
        $schoolId = $this->user()->school_id;

        return [
            'subject_id' => [
                'sometimes', 'required',
                Rule::exists('subjects', 'id')->whereIn(
                    'grade_id',
                    Grade::query()->select('id')->where('school_id', $schoolId),
                ),
            ],
            'staff_id' => ['nullable', Rule::exists('users', 'id')->where('school_id', $schoolId)],
            'day_of_week' => ['sometimes', 'required', Rule::enum(Weekday::class)],
            'starts_at' => ['sometimes', 'required', 'date_format:H:i'],
            'ends_at' => ['sometimes', 'required', 'date_format:H:i', 'after:starts_at'],
            'period_number' => [
                'sometimes', 'required', 'integer', 'min:1', 'max:20',
                Rule::unique('schedule_slots', 'period_number')
                    ->where('section_id', $slot->section_id)
                    ->where('term_id', $slot->term_id)
                    ->where('day_of_week', $this->input('day_of_week', $slot->day_of_week->value))
                    ->ignore($slot->id),
            ],
            'room' => ['nullable', 'string', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // "after:starts_at" needs the effective start time on a partial update.
        $slot = $this->route('slot');

        $this->merge([
            'starts_at' => $this->input('starts_at', substr((string) $slot->starts_at, 0, 5)),
        ]);
    }

    public function messages(): array
    {
        return ['period_number.unique' => __('messages.slot.period_taken')];
    }
}
