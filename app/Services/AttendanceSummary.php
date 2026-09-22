<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\ExcuseStatus;
use App\Models\AbsenceExcuse;
use App\Models\AttendanceRecord;
use Illuminate\Support\Collection;

/**
 * The four-state summary bar of the roll-call screen. The stored status has
 * three values; "excused absence" is derived from an accepted excuse covering
 * the day, and is never written to attendance_records (decision 4-a).
 */
class AttendanceSummary
{
    /**
     * @param  Collection<int, AttendanceRecord>  $records
     * @return array{present:int, late:int, excused:int, unexcused:int, recorded:int}
     */
    public static function for(Collection $records): array
    {
        $excusedStudentDays = self::excusedStudentDays($records);

        $summary = ['present' => 0, 'late' => 0, 'excused' => 0, 'unexcused' => 0];

        foreach ($records as $record) {
            $summary[match ($record->status) {
                AttendanceStatus::Present => 'present',
                AttendanceStatus::Late => 'late',
                AttendanceStatus::Absent => $excusedStudentDays->has(
                    self::key($record->student_id, $record->date->toDateString()),
                ) ? 'excused' : 'unexcused',
            }]++;
        }

        return [...$summary, 'recorded' => $records->count()];
    }

    /** Which of these (student, day) pairs an accepted excuse covers. */
    public static function excusedStudentDays(Collection $records): Collection
    {
        $absences = $records->where('status', AttendanceStatus::Absent);

        if ($absences->isEmpty()) {
            return collect();
        }

        $excuses = AbsenceExcuse::query()
            ->whereIn('student_id', $absences->pluck('student_id')->unique())
            ->where('status', ExcuseStatus::Accepted)
            // Bind Y-m-d strings: a Carbon binds as "Y-m-d H:i:s", and in SQLite
            // "2026-09-03" is not >= "2026-09-03 00:00:00", so a one-day excuse
            // covering the absence day would never match.
            ->where('start_date', '<=', $absences->max('date')->toDateString())
            ->where('end_date', '>=', $absences->min('date')->toDateString())
            ->get();

        return $absences
            ->filter(fn ($record) => $excuses->contains(
                fn (AbsenceExcuse $excuse) => $excuse->student_id === $record->student_id
                    && $excuse->start_date <= $record->date
                    && $excuse->end_date >= $record->date,
            ))
            ->keyBy(fn ($record) => self::key($record->student_id, $record->date->toDateString()));
    }

    public static function key(int $studentId, string $date): string
    {
        return $studentId.'|'.$date;
    }
}
