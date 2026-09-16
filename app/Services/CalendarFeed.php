<?php

namespace App\Services;

use App\Enums\CalendarEventType;
use App\Models\Assessment;
use App\Models\FeePlanInstallment;
use App\Models\Holiday;
use App\Models\Post;
use App\Models\ScheduleSlot;
use App\Models\Section;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The calendar has no table (decision 10-a): it is a union view over the
 * schedule, holidays, instalment due dates, dated posts and assessments.
 * That is also why the calendar screen has no "add event" button.
 */
class CalendarFeed
{
    /** Post types that read as calendar entries. */
    private const DATED_POST_TYPES = ['event', 'trip', 'meeting', 'reminder', 'activity'];

    /**
     * @return Collection<int, array{type:string, type_label:string, date:string, title:string, subtitle:?string, ref_id:int, starts_at:?string, ends_at:?string}>
     */
    public static function between(
        int $schoolId,
        string $from,
        string $to,
        ?int $sectionId = null,
        ?int $studentId = null,
    ): Collection {
        return collect()
            ->merge(self::periods($schoolId, $from, $to, $sectionId))
            ->merge(self::holidays($schoolId, $from, $to))
            ->merge(self::installments($schoolId, $from, $to, $studentId))
            ->merge(self::posts($schoolId, $from, $to))
            ->merge(self::assessments($schoolId, $from, $to, $sectionId))
            ->sortBy(['date', 'starts_at'])
            ->values();
    }

    /**
     * Periods repeat weekly, so each slot is expanded onto the matching days
     * inside the window.
     */
    private static function periods(int $schoolId, string $from, string $to, ?int $sectionId): Collection
    {
        if (! $sectionId) {
            return collect();
        }

        $slots = ScheduleSlot::query()
            ->ofSchool($schoolId)
            ->where('section_id', $sectionId)
            ->with(['subject', 'teacher'])
            ->get();

        if ($slots->isEmpty()) {
            return collect();
        }

        $events = collect();
        $cursor = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();

        while ($cursor->lte($end)) {
            // Carbon's dayOfWeek is 0=Sunday, matching the Weekday enum.
            foreach ($slots->where('day_of_week.value', $cursor->dayOfWeek) as $slot) {
                $events->push([
                    'type' => CalendarEventType::Period->value,
                    'type_label' => CalendarEventType::Period->label(),
                    'date' => $cursor->toDateString(),
                    'title' => $slot->subject?->name ?? '',
                    'subtitle' => $slot->teacher?->full_name,
                    'ref_id' => $slot->id,
                    'starts_at' => $slot->starts_at,
                    'ends_at' => $slot->ends_at,
                ]);
            }

            $cursor->addDay();
        }

        return $events;
    }

    private static function holidays(int $schoolId, string $from, string $to): Collection
    {
        return Holiday::query()
            ->ofSchool($schoolId)
            ->overlapping($from, $to)
            ->get()
            ->map(fn (Holiday $holiday) => [
                'type' => CalendarEventType::Holiday->value,
                'type_label' => CalendarEventType::Holiday->label(),
                'date' => $holiday->start_date->toDateString(),
                'end_date' => $holiday->end_date->toDateString(),
                'title' => $holiday->name,
                'subtitle' => null,
                'ref_id' => $holiday->id,
                'starts_at' => null,
                'ends_at' => null,
            ]);
    }

    private static function installments(int $schoolId, string $from, string $to, ?int $studentId): Collection
    {
        return FeePlanInstallment::query()
            ->whereHas('plan', fn ($q) => $q
                ->ofSchool($schoolId)
                ->when($studentId, fn ($sub) => $sub->where('student_id', $studentId)))
            ->whereBetween('due_date', [$from, $to])
            ->with('plan.student')
            ->get()
            ->map(fn (FeePlanInstallment $installment) => [
                'type' => CalendarEventType::Installment->value,
                'type_label' => CalendarEventType::Installment->label(),
                'date' => $installment->due_date->toDateString(),
                'title' => __('calendar_event_types.installment').' — '.$installment->amount()->toDecimal(),
                'subtitle' => $installment->plan?->student?->full_name,
                'ref_id' => $installment->id,
                'starts_at' => null,
                'ends_at' => null,
            ]);
    }

    private static function posts(int $schoolId, string $from, string $to): Collection
    {
        return Post::query()
            ->ofSchool($schoolId)
            ->published()
            ->whereHas('type', fn ($q) => $q->whereIn('key', self::DATED_POST_TYPES))
            ->whereBetween('published_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->with('type')
            ->get()
            ->map(fn (Post $post) => [
                'type' => CalendarEventType::Post->value,
                'type_label' => $post->type->name,
                'date' => $post->published_at->toDateString(),
                'title' => $post->title,
                'subtitle' => $post->body ? mb_substr($post->body, 0, 80) : null,
                'ref_id' => $post->id,
                'starts_at' => null,
                'ends_at' => null,
            ]);
    }

    /**
     * Assessments have no date column of their own, so they surface on the day
     * they were created — a known thinness of the union.
     */
    private static function assessments(int $schoolId, string $from, string $to, ?int $sectionId): Collection
    {
        return Assessment::query()
            ->ofSchool($schoolId)
            ->when($sectionId, fn ($q) => $q->whereHas(
                'subject',
                fn ($sub) => $sub->whereIn(
                    'grade_id',
                    Section::query()->select('grade_id')->whereKey($sectionId),
                ),
            ))
            ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->with('subject')
            ->get()
            ->map(fn (Assessment $assessment) => [
                'type' => CalendarEventType::Assessment->value,
                'type_label' => CalendarEventType::Assessment->label(),
                'date' => $assessment->created_at->toDateString(),
                'title' => $assessment->name,
                'subtitle' => $assessment->subject?->name,
                'ref_id' => $assessment->id,
                'starts_at' => null,
                'ends_at' => null,
            ]);
    }
}
