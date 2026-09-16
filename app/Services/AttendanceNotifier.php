<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\NotificationApp;
use App\Enums\Status;
use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * What a submitted roll call tells people.
 *
 * - Every guardian with the app hears how their child was marked today —
 *   present, late or absent (`attendance_present` / `attendance_late` /
 *   `attendance_absence`, guardian switches, so a school can keep only the
 *   absence alerts if it prefers).
 * - Administrators get one summary per section: present / late / absent
 *   counts with the absent names (`attendance_summary`, staff switch).
 * - When a student's unexcused absences in the current year reach the
 *   school's `absence_warning_threshold`, the administrators are told once —
 *   exactly on the day the threshold is crossed, so nobody is nagged daily.
 *
 * Only a *submitted* sheet notifies: partial saves during roll call are work
 * in progress and would spam families with corrections.
 */
class AttendanceNotifier
{
    public static function submitted(Section $section, string $date): void
    {
        $records = AttendanceRecord::query()
            ->where('section_id', $section->id)
            ->whereDate('date', $date)
            ->with(['student.guardians.user', 'student.school', 'student.currentEnrollment'])
            ->get();

        if ($records->isEmpty()) {
            return;
        }

        $excused = AttendanceSummary::excusedStudentDays($records);
        $placement = $section->loadMissing('grade');
        $sectionLabel = trim(($placement->grade?->name ?? '').' - '.$section->name, ' -');

        foreach ($records as $record) {
            $student = $record->student;
            $key = match ($record->status) {
                AttendanceStatus::Present => 'attendance_present',
                AttendanceStatus::Late => 'attendance_late',
                AttendanceStatus::Absent => 'attendance_absence',
            };
            $bodyKey = match ($record->status) {
                AttendanceStatus::Present => 'attendance_present',
                AttendanceStatus::Late => 'attendance_late',
                AttendanceStatus::Absent => 'attendance_absent',
            };

            foreach ($student->guardians as $guardian) {
                if (! $guardian->user) {
                    continue;
                }

                NotificationGate::notify(
                    $guardian->user,
                    $key,
                    __('notification_keys.'.$key).' — '.$student->full_name,
                    __('notifications.'.$bodyKey, ['date' => $date, 'section' => $sectionLabel]),
                    $student->id,
                    NotificationApp::Guardian,
                );
            }

            if ($record->status === AttendanceStatus::Absent
                && ! $excused->has(AttendanceSummary::key($student->id, $date))) {
                self::checkThreshold($student, $date, $sectionLabel);
            }
        }

        self::notifySummary($section, $date, $sectionLabel, $records);
    }

    /** One line for the office: counts plus who was absent. */
    private static function notifySummary(Section $section, string $date, string $sectionLabel, Collection $records): void
    {
        $present = $records->where('status', AttendanceStatus::Present)->count();
        $late = $records->where('status', AttendanceStatus::Late)->count();
        $absentRecords = $records->where('status', AttendanceStatus::Absent);
        $absentNames = $absentRecords->map(fn (AttendanceRecord $r) => $r->student->full_name)->take(10)->implode('، ');

        $body = __('notifications.attendance_summary_body', [
            'present' => $present,
            'late' => $late,
            'absent' => $absentRecords->count(),
        ]);
        if ($absentNames !== '') {
            $body .= ' — '.$absentNames.($absentRecords->count() > 10 ? '…' : '');
        }

        foreach (self::administrators($section->grade->school_id) as $admin) {
            NotificationGate::notify(
                $admin,
                'attendance_summary',
                __('notifications.attendance_summary_title', ['section' => $sectionLabel, 'date' => $date]),
                $body,
                $section->id,
                NotificationApp::Staff,
            );
        }
    }

    /** Tell the office the first time a student hits the school's limit. */
    private static function checkThreshold(Student $student, string $date, string $sectionLabel): void
    {
        $threshold = (int) ($student->school->absence_warning_threshold ?? 0);

        if ($threshold <= 0) {
            return;
        }

        $yearId = $student->currentEnrollment?->academic_year_id;

        $unexcused = AttendanceRecord::query()
            ->where('student_id', $student->id)
            ->when($yearId, fn ($q) => $q->whereHas('section', fn ($s) => $s->where('academic_year_id', $yearId)))
            ->unexcused()
            ->count();

        // Notify exactly when the count lands on the threshold — not below,
        // and not again on every later absence.
        if ($unexcused !== $threshold) {
            return;
        }

        foreach (self::administrators($student->school_id) as $admin) {
            NotificationGate::notify(
                $admin,
                'attendance_absence',
                __('notifications.absence_threshold_title', ['name' => $student->full_name]),
                __('notifications.absence_threshold_body', [
                    'count' => $unexcused,
                    'section' => $sectionLabel,
                    'date' => $date,
                ]),
                $student->id,
                NotificationApp::Staff,
            );
        }
    }

    /** @return Collection<int, User> */
    private static function administrators(int $schoolId): Collection
    {
        return User::query()
            ->where('school_id', $schoolId)
            ->whereIn('role', [UserRole::Admin->value, UserRole::SuperAdmin->value])
            ->where('status', Status::Active)
            ->get();
    }
}
