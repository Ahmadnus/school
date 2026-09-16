<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;

/**
 * The attendance summary shown at the top of a student's profile.
 *
 * Counts come straight from attendance_records; "excused" is derived from an
 * accepted excuse covering the day (decision 4-a), so the four states add up
 * to the number of recorded days. Two rates are returned:
 *
 * - `attendance_rate`: (present + late) / recorded — the raw figure.
 * - `effective_rate`: (present + late) / (recorded − excused) — the rate the
 *   school actually judges by, where an excused day does not count against
 *   the student. Both are null when nothing has been recorded.
 */
class StudentAttendanceSummary
{
    /**
     * @param  (\Closure(Builder): mixed)|null  $constrain  Extra filters (date range, section…).
     * @return array{present:int, late:int, absent:int, excused:int, unexcused:int, recorded:int, attendance_rate:?float, effective_rate:?float}
     */
    public static function for(Student $student, ?\Closure $constrain = null): array
    {
        $query = AttendanceRecord::query()->where('student_id', $student->id);

        if ($constrain) {
            $constrain($query);
        }

        $records = $query->get(['id', 'student_id', 'date', 'status']);
        $base = AttendanceSummary::for($records);

        $recorded = $base['recorded'];
        $attended = $base['present'] + $base['late'];
        $judged = $recorded - $base['excused'];

        return [
            'present' => $base['present'],
            'late' => $base['late'],
            'absent' => $base['excused'] + $base['unexcused'],
            'excused' => $base['excused'],
            'unexcused' => $base['unexcused'],
            'recorded' => $recorded,
            'attendance_rate' => $recorded > 0 ? round($attended / $recorded * 100, 1) : null,
            'effective_rate' => $judged > 0 ? round($attended / $judged * 100, 1) : null,
        ];
    }
}
