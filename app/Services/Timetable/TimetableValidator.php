<?php

namespace App\Services\Timetable;

use App\Models\ScheduleSlot;
use App\Models\SchoolDayHours;
use App\Models\Section;
use App\Models\SectionDayHours;
use App\Models\TeacherAssignment;
use App\Models\TeacherAvailability;
use App\Models\TimetableRun;
use Illuminate\Support\Collection;

/**
 * يفحص جدولاً **مكتوباً** بحثاً عن تعارضٍ صلب.
 *
 * يُستدعى في موضعين، وهذا سبب وجوده خدمةً مستقلّة:
 *
 *  1. **بعد التوليد**، من قاعدة البيانات لا من ذاكرة المولّد: مولّدٌ يصدّق على
 *     نفسه يخطئ مرّتين ويقول «تمّ». وخطأٌ هنا يمحو حصص المحاولة ويعلنها
 *     مستحيلة بدل أن يترك جدولاً متعارضاً قائماً.
 *  2. **عند كل تعديل يدويّ**: المدير يسحب حصّة إلى موضع، فيُفحَص الموضع قبل
 *     الحفظ. لا يُسمح بحفظ تعارضٍ صلب أبداً، ولو كان الفاعل مديراً.
 */
class TimetableValidator
{
    /** يفحص حصص محاولةٍ مولَّدة ومعها الحصص اليدويّة في الفصل نفسه. */
    public static function validateRun(TimetableRun $run): array
    {
        $slots = ScheduleSlot::query()
            ->where('term_id', $run->term_id)
            ->with(['section.grade', 'subject', 'teacher'])
            ->get();

        return self::validateSlots($slots, $run->school_id);
    }

    /**
     * يفحص موضعاً مقترحاً لحصّةٍ واحدة — قبل الحفظ.
     *
     * `$ignoreSlotId` هي الحصّة نفسها عند تحريكها: بلا استثنائها تتعارض
     * الحصّة مع موضعها القديم فيُرفض كل تعديل.
     *
     * @return list<array<string, mixed>> فارغة = الموضع صالح.
     */
    public static function validatePlacement(
        int $sectionId,
        int $termId,
        int $subjectId,
        ?int $staffId,
        int $day,
        string $startsAt,
        string $endsAt,
        ?int $ignoreSlotId = null,
    ): array {
        $lesson = TimeRange::of($startsAt, $endsAt);
        $conflicts = [];

        if ($lesson->isEmpty()) {
            $conflicts[] = (new TimetableConflict('lesson_has_no_duration'))->toArray();

            return $conflicts;
        }

        $section = SectionDayHours::query()
            ->where('section_id', $sectionId)
            ->where('day_of_week', $day)
            ->first();

        $sectionModel = Section::query()->with('grade')->find($sectionId);
        $schoolId = $sectionModel?->grade?->school_id;

        // --- دوام المعهد: الحدّ الخارجي.
        $schoolHours = $schoolId === null ? null : SchoolDayHours::query()
            ->ofSchool($schoolId)
            ->where('day_of_week', $day)
            ->first();

        if ($schoolId !== null) {
            $anyConfigured = SchoolDayHours::query()->ofSchool($schoolId)->exists();

            if ($anyConfigured && $schoolHours === null) {
                $conflicts[] = (new TimetableConflict('school_closed_that_day'))->toArray();
            } elseif ($schoolHours !== null) {
                $window = TimeRange::of(
                    (string) $schoolHours->starts_at,
                    (string) $schoolHours->ends_at,
                );

                if (! $window->contains($lesson)) {
                    $conflicts[] = (new TimetableConflict('outside_school_hours', [
                        'window' => (string) $window,
                    ]))->toArray();
                }
            }
        }

        // --- دوام الشعبة: يضيّق دوام المعهد.
        $sectionWeek = SectionDayHours::query()->where('section_id', $sectionId)->exists();

        if ($sectionWeek && $section === null) {
            $conflicts[] = (new TimetableConflict('section_closed_that_day'))->toArray();
        } elseif ($section !== null) {
            $window = TimeRange::of((string) $section->starts_at, (string) $section->ends_at);

            if (! $window->contains($lesson)) {
                $conflicts[] = (new TimetableConflict('outside_section_hours', [
                    'window' => (string) $window,
                ]))->toArray();
            }
        }

        if ($staffId !== null) {
            // --- الإسناد: أستاذٌ لا يُدرّس إلاّ ما أُسند إليه.
            $assigned = TeacherAssignment::query()
                ->where('staff_id', $staffId)
                ->where('subject_id', $subjectId)
                ->where('section_id', $sectionId)
                ->exists();

            if (! $assigned) {
                $conflicts[] = (new TimetableConflict('teacher_not_assigned'))->toArray();
            }

            // --- التفرّغ: الحصّة تدخل بكاملها في مدًى واحد.
            $ranges = TeacherAvailability::query()
                ->where('staff_id', $staffId)
                ->where('day_of_week', $day)
                ->get();

            $free = $ranges->contains(
                fn (TeacherAvailability $r) => TimeRange::of(
                    (string) $r->starts_at,
                    (string) $r->ends_at,
                )->contains($lesson),
            );

            if ($ranges->isNotEmpty() && ! $free) {
                $conflicts[] = (new TimetableConflict('teacher_unavailable'))->toArray();
            }

            if ($ranges->isEmpty()) {
                $conflicts[] = (new TimetableConflict('teacher_unavailable'))->toArray();
            }
        }

        // --- التعارض مع حصصٍ قائمة، في الفصل نفسه واليوم نفسه.
        $existing = ScheduleSlot::query()
            ->where('term_id', $termId)
            ->where('day_of_week', $day)
            ->when($ignoreSlotId, fn ($q) => $q->whereKeyNot($ignoreSlotId))
            ->where(function ($q) use ($sectionId, $staffId) {
                $q->where('section_id', $sectionId);

                if ($staffId !== null) {
                    $q->orWhere('staff_id', $staffId);
                }
            })
            ->with(['section.grade', 'subject', 'teacher'])
            ->get();

        foreach ($existing as $slot) {
            $other = TimeRange::of((string) $slot->starts_at, (string) $slot->ends_at);

            if (! $other->overlaps($lesson)) {
                continue;
            }

            if ($staffId !== null && $slot->staff_id === $staffId) {
                $conflicts[] = (new TimetableConflict('teacher_double_booked', [
                    'teacher' => $slot->teacher?->full_name ?? '',
                    'section' => self::label($slot),
                    'time' => (string) $other,
                ], staffId: $staffId))->toArray();
            }

            if ($slot->section_id === $sectionId) {
                $conflicts[] = (new TimetableConflict('section_double_booked', [
                    'section' => self::label($slot),
                    'subject' => $slot->subject?->name ?? '',
                    'time' => (string) $other,
                ], sectionId: $sectionId))->toArray();
            }
        }

        return $conflicts;
    }

    /**
     * يفحص مجموعة حصص بحثاً عن تعارضٍ بينها.
     *
     * @param  Collection<int, ScheduleSlot>  $slots
     */
    public static function validateSlots(Collection $slots, int $schoolId): array
    {
        $conflicts = [];

        $byStaffDay = [];
        $bySectionDay = [];

        foreach ($slots as $slot) {
            $day = $slot->day_of_week->value;
            $range = TimeRange::of((string) $slot->starts_at, (string) $slot->ends_at);

            if ($slot->staff_id !== null) {
                foreach ($byStaffDay["{$slot->staff_id}:{$day}"] ?? [] as [$otherRange, $other]) {
                    if ($otherRange->overlaps($range)) {
                        $conflicts[] = (new TimetableConflict('teacher_double_booked', [
                            'teacher' => $slot->teacher?->full_name ?? '',
                            'section' => self::label($slot).' / '.self::label($other),
                            'time' => (string) $range,
                        ], staffId: $slot->staff_id))->toArray();
                    }
                }

                $byStaffDay["{$slot->staff_id}:{$day}"][] = [$range, $slot];
            }

            foreach ($bySectionDay["{$slot->section_id}:{$day}"] ?? [] as [$otherRange, $other]) {
                if ($otherRange->overlaps($range)) {
                    $conflicts[] = (new TimetableConflict('section_double_booked', [
                        'section' => self::label($slot),
                        'subject' => ($slot->subject?->name ?? '').' / '.($other->subject?->name ?? ''),
                        'time' => (string) $range,
                    ], sectionId: $slot->section_id))->toArray();
                }
            }

            $bySectionDay["{$slot->section_id}:{$day}"][] = [$range, $slot];
        }

        return array_values($conflicts);
    }

    private static function label(ScheduleSlot $slot): string
    {
        return trim(
            ($slot->section?->grade?->name ?? '').' - '.($slot->section?->name ?? ''),
            ' -',
        );
    }
}
