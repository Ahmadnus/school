<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\BehaviorStatus;
use App\Enums\BehaviorType;
use App\Models\AttendanceRecord;
use App\Models\BehaviorRecord;
use App\Models\FeePlanInstallment;
use App\Models\GradeScore;
use App\Models\School;
use App\Models\Student;
use Illuminate\Support\Collection;

/**
 * Student attention signals — deterministic, explainable, neutral.
 *
 * Every signal is a plain rule over data the school already records; nothing
 * here is a diagnosis. The wording is "follow-up recommended" / "needs
 * attention", and each signal carries the detail that justified it so a
 * supervisor can see *why* — "attendance fell from 94% to 81%" — not just a
 * flag. The absence rule uses the school's own `absence_warning_threshold`.
 */
class StudentSignals
{
    public const LEVEL_NONE = 'none';

    public const LEVEL_FOLLOW_UP = 'follow_up';

    public const LEVEL_ATTENTION = 'attention';

    /**
     * @return array{signals: array<int, array{key:string, severity:string, title:string, detail:string}>, level:string}
     */
    public static function forStudent(Student $student, School $school, bool $includeFees = true): array
    {
        $signals = [];
        $today = now()->startOfDay();
        $windowStart = $today->copy()->subDays(30);
        $previousStart = $today->copy()->subDays(60);

        // (a) attendance drop: last 30 days vs the 30 before.
        $recent = self::rate($student, $windowStart->toDateString(), $today->toDateString());
        $previous = self::rate($student, $previousStart->toDateString(), $windowStart->copy()->subDay()->toDateString());

        if ($recent !== null && $previous !== null && $previous - $recent >= 10) {
            $signals[] = self::signal('attendance_drop', 'warning', [
                'from' => (int) round($previous),
                'to' => (int) round($recent),
            ]);
        }

        // (b) unexcused absences this year vs school threshold.
        $threshold = (int) ($school->absence_warning_threshold ?? 0);
        if ($threshold > 0) {
            $yearId = $student->currentEnrollment?->academic_year_id;
            $unexcused = AttendanceRecord::query()
                ->where('student_id', $student->id)
                ->when($yearId, fn ($q) => $q->whereHas('section', fn ($s) => $s->where('academic_year_id', $yearId)))
                ->unexcused()
                ->count();

            if ($unexcused >= $threshold) {
                $signals[] = self::signal('unexcused_absences', 'danger', [
                    'count' => $unexcused,
                    'threshold' => $threshold,
                ]);
            }
        }

        // (c) repeated lateness in the last 30 days.
        $late = AttendanceRecord::query()
            ->where('student_id', $student->id)
            ->where('status', AttendanceStatus::Late)
            ->whereDate('date', '>=', $windowStart->toDateString())
            ->count();

        if ($late >= 3) {
            $signals[] = self::signal('repeated_lateness', 'warning', ['count' => $late]);
        }

        // (d) open negative behaviour records in 60 days.
        $incidents = BehaviorRecord::query()
            ->where('student_id', $student->id)
            ->where('status', BehaviorStatus::Open)
            ->whereIn('type', [BehaviorType::Negative->value, BehaviorType::Warning->value, BehaviorType::Incident->value])
            ->whereDate('occurred_on', '>=', $previousStart->toDateString())
            ->count();

        if ($incidents >= 2) {
            $signals[] = self::signal('behavior_incidents', 'danger', ['count' => $incidents]);
        }

        // (e) overdue installments — only for callers allowed to see money.
        if ($includeFees) {
            $overdue = self::overdueInstallments($student);

            if ($overdue > 0) {
                $signals[] = self::signal('overdue_fees', 'warning', ['count' => $overdue]);
            }
        }

        // (f) academic decline: recent scores vs earlier ones, as a percentage of max.
        $decline = self::academicDecline($student, $windowStart->toDateString());
        if ($decline !== null) {
            $signals[] = self::signal('academic_decline', 'warning', $decline);
        }

        return ['signals' => $signals, 'level' => self::level($signals)];
    }

    /** The level from a list of signals: one → follow-up, two or a danger → attention. */
    public static function level(array $signals): string
    {
        if ($signals === []) {
            return self::LEVEL_NONE;
        }

        $danger = collect($signals)->contains(fn (array $s) => $s['severity'] === 'danger');

        return ($danger || count($signals) >= 2) ? self::LEVEL_ATTENTION : self::LEVEL_FOLLOW_UP;
    }

    /**
     * Cheap candidate detection for a whole school: students that have at least
     * one grouped indicator (unexcused ≥ threshold, late ≥ 3, incidents ≥ 2,
     * overdue installments). The full rule set then runs for candidates only.
     *
     * @param  Collection<int, int>  $studentIds
     * @return Collection<int, int>
     */
    public static function candidates(School $school, Collection $studentIds, bool $includeFees): Collection
    {
        if ($studentIds->isEmpty()) {
            return collect();
        }

        $today = now()->startOfDay();
        $ids = collect();

        $threshold = (int) ($school->absence_warning_threshold ?? 0);
        if ($threshold > 0) {
            $ids = $ids->merge(
                AttendanceRecord::query()
                    ->whereIn('student_id', $studentIds)
                    ->unexcused()
                    ->groupBy('student_id')
                    ->havingRaw('count(*) >= ?', [$threshold])
                    ->pluck('student_id'),
            );
        }

        $ids = $ids->merge(
            AttendanceRecord::query()
                ->whereIn('student_id', $studentIds)
                ->where('status', AttendanceStatus::Late)
                ->whereDate('date', '>=', $today->copy()->subDays(30)->toDateString())
                ->groupBy('student_id')
                ->havingRaw('count(*) >= 3')
                ->pluck('student_id'),
        );

        $ids = $ids->merge(
            BehaviorRecord::query()
                ->whereIn('student_id', $studentIds)
                ->where('status', BehaviorStatus::Open)
                ->whereIn('type', [BehaviorType::Negative->value, BehaviorType::Warning->value, BehaviorType::Incident->value])
                ->whereDate('occurred_on', '>=', $today->copy()->subDays(60)->toDateString())
                ->groupBy('student_id')
                ->havingRaw('count(*) >= 2')
                ->pluck('student_id'),
        );

        if ($includeFees) {
            $ids = $ids->merge(
                FeePlanInstallment::query()
                    ->join('fee_plans', 'fee_plans.id', '=', 'fee_plan_installments.fee_plan_id')
                    ->whereIn('fee_plans.student_id', $studentIds)
                    ->where('fee_plans.status', 'active')
                    ->whereDate('fee_plan_installments.due_date', '<', $today->toDateString())
                    ->whereRaw('fee_plan_installments.amount_minor > (select coalesce(sum(a.amount_minor), 0)'
                        .' from fee_payment_allocations a join fee_payments p on p.id = a.fee_payment_id'
                        .' where a.fee_plan_installment_id = fee_plan_installments.id and p.voided_at is null)')
                    ->distinct()
                    ->pluck('fee_plans.student_id'),
            );
        }

        // Attendance drops and academic decline have no cheap grouped form;
        // students with any attendance in the window are checked in full.
        $ids = $ids->merge(
            AttendanceRecord::query()
                ->whereIn('student_id', $studentIds)
                ->whereIn('status', [AttendanceStatus::Absent->value, AttendanceStatus::Late->value])
                ->whereDate('date', '>=', $today->copy()->subDays(30)->toDateString())
                ->distinct()
                ->pluck('student_id'),
        );

        return $ids->map(fn ($id) => (int) $id)->unique()->values();
    }

    private static function rate(Student $student, string $from, string $to): ?float
    {
        $summary = StudentAttendanceSummary::for(
            $student,
            fn ($q) => $q->whereDate('date', '>=', $from)->whereDate('date', '<=', $to),
        );

        return $summary['recorded'] >= 5 ? $summary['effective_rate'] : null;
    }

    private static function overdueInstallments(Student $student): int
    {
        $today = now()->toDateString();

        return $student->feePlans()
            ->where('status', 'active')
            ->with('installments.activeAllocations')
            ->get()
            ->flatMap(fn ($plan) => $plan->installments)
            ->filter(fn ($i) => $i->isOverdue())
            ->count();
    }

    /** @return array{from:int, to:int}|null */
    private static function academicDecline(Student $student, string $windowStart): ?array
    {
        $scores = GradeScore::query()
            ->where('student_id', $student->id)
            ->whereNotNull('score')
            ->with('assessment:id,max_score')
            ->get();

        $recent = $scores->filter(fn ($s) => $s->entered_at !== null && $s->entered_at->toDateString() >= $windowStart);
        $earlier = $scores->filter(fn ($s) => $s->entered_at === null || $s->entered_at->toDateString() < $windowStart);

        if ($recent->count() < 2 || $earlier->count() < 2) {
            return null;
        }

        $percent = fn ($s) => $s->assessment && (float) $s->assessment->max_score > 0
            ? (float) $s->score / (float) $s->assessment->max_score * 100
            : null;

        $recentAvg = $recent->map($percent)->filter()->avg();
        $earlierAvg = $earlier->map($percent)->filter()->avg();

        if ($recentAvg === null || $earlierAvg === null || $earlierAvg <= 0) {
            return null;
        }

        if (($earlierAvg - $recentAvg) / $earlierAvg * 100 >= 15) {
            return ['from' => (int) round($earlierAvg), 'to' => (int) round($recentAvg)];
        }

        return null;
    }

    private static function signal(string $key, string $severity, array $params): array
    {
        return [
            'key' => $key,
            'severity' => $severity,
            'title' => __('signals.'.$key.'.title'),
            'detail' => __('signals.'.$key.'.detail', $params),
        ];
    }
}
