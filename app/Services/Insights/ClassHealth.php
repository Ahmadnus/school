<?php

namespace App\Services\Insights;

use App\Enums\AttendanceSessionStatus;
use App\Enums\AttendanceStatus;
use App\Enums\BehaviorStatus;
use App\Enums\FeePlanStatus;
use App\Models\AcademicYear;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\BehaviorRecord;
use App\Models\FeePlan;
use App\Models\Section;
use App\Models\StudentEnrollment;
use Illuminate\Support\Facades\DB;

/**
 * One explainable status per section: attendance over the last 30 days,
 * lateness, unexcused absences, open behaviour records and (for
 * administrators) the outstanding fees of its students. Every status carries
 * the reasons that produced it — never a bare colour.
 */
class ClassHealth
{
    public const WINDOW_DAYS = 30;

    /** @return array<int, array<string, mixed>> */
    public static function for(InsightContext $ctx, ?int $academicYearId = null): array
    {
        $year = $academicYearId
            ? AcademicYear::query()->ofSchool($ctx->school->id)->find($academicYearId)
            : $ctx->year;

        if (! $year) {
            return [];
        }

        $sections = Section::query()
            ->where('academic_year_id', $year->id)
            ->whereHas('grade', fn ($g) => $g->where('school_id', $ctx->school->id))
            ->when($ctx->isTeacher(), fn ($q) => $q->whereIn('id', $ctx->teacherSectionIds()))
            ->with('grade')
            ->withCount(['enrollments as students_count' => fn ($q) => $q->where('status', 'active')])
            ->ordered()
            ->get();

        if ($sections->isEmpty()) {
            return [];
        }

        $ids = $sections->pluck('id');
        $since = $ctx->today->copy()->subDays(self::WINDOW_DAYS)->toDateString();

        // Attendance counts per section over the window, one grouped query.
        $counts = AttendanceRecord::query()
            ->whereIn('section_id', $ids)
            ->whereDate('date', '>=', $since)
            ->select('section_id', 'status', DB::raw('count(*) as c'))
            ->groupBy('section_id', 'status')
            ->get()
            ->groupBy('section_id');

        $unexcused = AttendanceRecord::query()
            ->whereIn('section_id', $ids)
            ->whereDate('date', '>=', $since)
            ->unexcused()
            ->select('section_id', DB::raw('count(*) as c'))
            ->groupBy('section_id')
            ->pluck('c', 'section_id');

        $submittedDays = AttendanceSession::query()
            ->whereIn('section_id', $ids)
            ->where('status', AttendanceSessionStatus::Submitted->value)
            ->whereDate('date', '>=', $since)
            ->select('section_id', DB::raw('count(*) as c'))
            ->groupBy('section_id')
            ->pluck('c', 'section_id');

        $studentSection = StudentEnrollment::query()
            ->whereIn('section_id', $ids)
            ->where('status', 'active')
            ->pluck('section_id', 'student_id');

        $behavior = BehaviorRecord::query()
            ->whereIn('student_id', $studentSection->keys())
            ->where('status', BehaviorStatus::Open->value)
            ->whereDate('occurred_on', '>=', $since)
            ->select('student_id', DB::raw('count(*) as c'))
            ->groupBy('student_id')
            ->pluck('c', 'student_id');

        $behaviorBySection = [];
        foreach ($behavior as $studentId => $c) {
            $sid = $studentSection[$studentId] ?? null;
            if ($sid) {
                $behaviorBySection[$sid] = ($behaviorBySection[$sid] ?? 0) + (int) $c;
            }
        }

        $feesBySection = [];
        if ($ctx->isAdministrative()) {
            $plans = FeePlan::query()
                ->whereIn('student_id', $studentSection->keys())
                ->where('academic_year_id', $year->id)
                ->where('status', FeePlanStatus::Active)
                ->with('activePayments')
                ->get();
            foreach ($plans as $plan) {
                $sid = $studentSection[$plan->student_id] ?? null;
                if ($sid) {
                    $feesBySection[$sid] = ($feesBySection[$sid] ?? 0) + ($plan->remainingAmount()->minor / 100);
                }
            }
        }

        $threshold = (int) ($ctx->school->absence_warning_threshold ?? 0);
        $rows = [];

        foreach ($sections as $section) {
            $byStatus = ($counts[$section->id] ?? collect())->keyBy(fn ($r) => $r->status instanceof AttendanceStatus ? $r->status->value : $r->status);
            $present = (int) ($byStatus['present']->c ?? 0);
            $late = (int) ($byStatus['late']->c ?? 0);
            $absent = (int) ($byStatus['absent']->c ?? 0);
            $recorded = $present + $late + $absent;
            $unex = (int) ($unexcused[$section->id] ?? 0);
            $rate = $recorded > 0 ? round(($present + $late) / $recorded * 100, 1) : null;
            $beh = $behaviorBySection[$section->id] ?? 0;

            $reasons = [];
            $status = 'healthy';
            if ($rate !== null && $rate < 75) {
                $status = 'review';
                $reasons[] = __('insights.class_health.low_attendance', ['rate' => $rate]);
            } elseif ($rate !== null && $rate < 85) {
                $status = 'attention';
                $reasons[] = __('insights.class_health.attendance_slipping', ['rate' => $rate]);
            }
            if ($threshold > 0 && $section->students_count > 0 && $unex >= $threshold * max(1, (int) ceil($section->students_count / 4))) {
                $status = $status === 'review' ? 'review' : 'attention';
                $reasons[] = __('insights.class_health.many_unexcused', ['count' => $unex]);
            }
            if ($beh >= 3) {
                $status = $status === 'review' ? 'review' : 'attention';
                $reasons[] = __('insights.class_health.behavior_activity', ['count' => $beh]);
            }
            if ($late >= 10) {
                $reasons[] = __('insights.class_health.frequent_lateness', ['count' => $late]);
                if ($status === 'healthy') {
                    $status = 'attention';
                }
            }

            $rows[] = [
                'section_id' => $section->id,
                'grade_id' => $section->grade_id,
                'name' => trim(($section->grade?->name ?? '').' - '.$section->name, ' -'),
                'students_count' => (int) $section->students_count,
                'window_days' => self::WINDOW_DAYS,
                'attendance_rate' => $rate,
                'present' => $present,
                'late' => $late,
                'absent' => $absent,
                'unexcused' => $unex,
                'submitted_days' => (int) ($submittedDays[$section->id] ?? 0),
                'open_behavior' => $beh,
                'remaining_fees' => $ctx->isAdministrative()
                    ? number_format($feesBySection[$section->id] ?? 0, 2, '.', '')
                    : null,
                'status' => $status,
                'status_label' => __('insights.class_health.status.'.$status),
                'reasons' => $reasons,
            ];
        }

        return $rows;
    }
}
