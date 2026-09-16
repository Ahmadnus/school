<?php

namespace App\Http\Requests\Academic;

use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Bulk grade entry. The screen saves the whole grid at once and a partial
 * class is allowed, so a null score is a valid value, not a missing one.
 */
class StoreScoresRequest extends FormRequest
{
    public function rules(): array
    {
        $assessment = $this->route('assessment');
        $schoolId = $this->user()->school_id;

        return [
            'scores' => ['required', 'array', 'min:1'],
            'scores.*.student_id' => [
                'required', 'distinct',
                Rule::exists('students', 'id')->where('school_id', $schoolId),
            ],
            'scores.*.score' => ['nullable', 'numeric', 'min:0', 'max:'.$assessment->max_score],
            'scores.*.rubric_level_id' => [
                'nullable',
                Rule::exists('rubric_levels', 'id')->whereIn(
                    'rubric_id',
                    \App\Models\Rubric::query()->select('id')->where('school_id', $schoolId),
                ),
            ],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $ids = collect($this->input('scores', []))->pluck('student_id')->filter()->all();

            if ($ids === []) {
                return;
            }

            // Grades belong to the running year: only currently enrolled students.
            $enrolled = Student::query()
                ->whereIn('id', $ids)
                ->currentYear()
                ->pluck('id')
                ->all();

            foreach ($this->input('scores', []) as $index => $row) {
                if (isset($row['student_id']) && ! in_array((int) $row['student_id'], $enrolled, true)) {
                    $validator->errors()->add(
                        "scores.$index.student_id",
                        __('messages.score.not_enrolled'),
                    );
                }
            }
        });
    }
}
