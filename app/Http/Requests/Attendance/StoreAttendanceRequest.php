<?php

namespace App\Http\Requests\Attendance;

use App\Enums\AttendanceStatus;
use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Roll call for one section on one day. Partial submission is allowed
 * (the screen shows "3/11"), so only the rows sent are written.
 */
class StoreAttendanceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'date' => ['required', 'date'],
            'records' => ['required', 'array', 'min:1'],
            'records.*.student_id' => ['required', 'distinct', 'integer'],
            'records.*.status' => ['required', Rule::enum(AttendanceStatus::class)],
            'submit' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $section = $this->route('section');
            $ids = collect($this->input('records', []))->pluck('student_id')->filter()->all();

            if ($ids === []) {
                return;
            }

            // Attendance belongs to the section's own roster in the running year.
            $roster = Student::query()
                ->whereIn('id', $ids)
                ->inSection($section->id)
                ->pluck('id')
                ->all();

            foreach ($this->input('records', []) as $index => $row) {
                if (! in_array((int) $row['student_id'], $roster, true)) {
                    $validator->errors()->add(
                        "records.$index.student_id",
                        __('messages.attendance.not_in_section'),
                    );
                }
            }
        });
    }
}
