<?php

namespace App\Http\Controllers\Api;

use App\Enums\Weekday;
use App\Http\Controllers\Controller;
use App\Models\ScheduleSlot;
use App\Models\Section;
use App\Models\SectionDayHours;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * بناء الجدول بخطوتين بدل ستّ حقول لكل حصّة.
 *
 *  1. دوام اليوم مرّة واحدة  — `PUT sections/{section}/hours`
 *  2. المواد على الحصص       — `PUT sections/{section}/schedule`
 *
 * الأوقات تُشتقّ من الدوام فلا تُكتب، والأستاذ لا يُسأل عنه هنا: الجدول
 * مواد وأوقات، ومن يدرّس ماذا يُعرَف من إسناد المواد.
 */
class SectionScheduleController extends Controller
{
    /** دوام الأسبوع لهذه الشعبة؛ يوم بلا صفّ يوم عطلة لها. */
    public function hours(Section $section): JsonResponse
    {
        $this->authorize('view', $section);

        return response()->json(['data' => $this->weekOf($section)]);
    }

    /**
     * يضبط دوام يوم أو عدّة أيام دفعة واحدة.
     *
     * الدفعة مقصودة: «الأحد إلى الخميس ١١:٣٠ ← ٥:٠٠» ضبطة واحدة لا خمس.
     */
    public function setHours(Request $request, Section $section): JsonResponse
    {
        $this->authorize('update', $section);

        $data = $request->validate([
            'days' => ['required', 'array', 'min:1'],
            'days.*' => [Rule::in(array_column(Weekday::cases(), 'value'))],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
            'period_minutes' => ['required', 'integer', 'min:5', 'max:240'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:120'],
        ]);

        foreach ($data['days'] as $day) {
            SectionDayHours::updateOrCreate(
                ['section_id' => $section->id, 'day_of_week' => $day],
                [
                    'starts_at' => $data['starts_at'],
                    'ends_at' => $data['ends_at'],
                    'period_minutes' => $data['period_minutes'],
                    'break_minutes' => $data['break_minutes'] ?? 0,
                ],
            );
        }

        return response()->json([
            'message' => __('messages.schedule.hours_saved'),
            'data' => $this->weekOf($section),
        ]);
    }

    /** يُلغي دوام يوم — يصير عطلة لهذه الشعبة. */
    public function clearHours(Request $request, Section $section): JsonResponse
    {
        $this->authorize('update', $section);

        $day = $request->integer('day');

        SectionDayHours::query()
            ->where('section_id', $section->id)
            ->where('day_of_week', $day)
            ->delete();

        return response()->json([
            'message' => __('messages.schedule.hours_cleared'),
            'data' => $this->weekOf($section),
        ]);
    }

    /**
     * شبكة يوم: الحصص بأوقاتها المشتقّة، وما هو مُسنَد إليها الآن.
     *
     * هذه هي الشاشة التي يؤشّر فيها المستخدم المواد — فتصل جاهزة بالأرقام
     * والأوقات، ولا يبقى عليه إلا المادة.
     */
    public function grid(Request $request, Section $section): JsonResponse
    {
        $this->authorize('view', $section);

        $day = $request->integer('day');
        $termId = $request->integer('term_id') ?: null;

        $hours = SectionDayHours::query()
            ->where('section_id', $section->id)
            ->where('day_of_week', $day)
            ->first();

        if (! $hours) {
            return response()->json(['data' => ['periods' => [], 'has_hours' => false]]);
        }

        $existing = ScheduleSlot::query()
            ->where('section_id', $section->id)
            ->where('day_of_week', $day)
            ->when($termId, fn ($q) => $q->where('term_id', $termId))
            ->get()
            ->keyBy('period_number');

        $periods = array_map(function (array $period) use ($existing) {
            $slot = $existing->get($period['period_number']);

            return [
                ...$period,
                'subject_id' => $slot?->subject_id,
                'slot_id' => $slot?->id,
            ];
        }, $hours->periods());

        return response()->json([
            'data' => [
                'has_hours' => true,
                'starts_at' => substr((string) $hours->starts_at, 0, 5),
                'ends_at' => substr((string) $hours->ends_at, 0, 5),
                'period_minutes' => $hours->period_minutes,
                'break_minutes' => $hours->break_minutes,
                'periods' => $periods,
            ],
        ]);
    }

    /**
     * يحفظ مواد اليوم كلّها في نداء واحد.
     *
     * حصّة بلا مادة تُحذف بدل أن تبقى فارغة: الجدول يقول ما يُدرَّس، والفراغ
     * يقوله بغياب الصفّ لا بصفّ فارغ.
     */
    public function setGrid(Request $request, Section $section): JsonResponse
    {
        $this->authorize('update', $section);

        $data = $request->validate([
            'day' => ['required', Rule::in(array_column(Weekday::cases(), 'value'))],
            'term_id' => ['required', Rule::exists('terms', 'id')],
            'periods' => ['present', 'array'],
            'periods.*.period_number' => ['required', 'integer', 'min:1', 'max:20'],
            'periods.*.subject_id' => ['nullable', Rule::exists('subjects', 'id')],
        ]);

        $hours = SectionDayHours::query()
            ->where('section_id', $section->id)
            ->where('day_of_week', $data['day'])
            ->first();

        if (! $hours) {
            return response()->json(['message' => __('messages.schedule.no_hours')], 422);
        }

        // الأوقات من الدوام لا من العميل: العميل يقول أي حصّة، والخادم يقول متى.
        $times = collect($hours->periods())->keyBy('period_number');

        DB::transaction(function () use ($data, $section, $times) {
            foreach ($data['periods'] as $row) {
                $number = (int) $row['period_number'];
                $time = $times->get($number);

                if (! $time) {
                    continue;
                }

                $where = [
                    'section_id' => $section->id,
                    'term_id' => $data['term_id'],
                    'day_of_week' => $data['day'],
                    'period_number' => $number,
                ];

                if (empty($row['subject_id'])) {
                    ScheduleSlot::query()->where($where)->delete();

                    continue;
                }

                ScheduleSlot::updateOrCreate($where, [
                    'subject_id' => $row['subject_id'],
                    'starts_at' => $time['starts_at'],
                    'ends_at' => $time['ends_at'],
                ]);
            }
        });

        return response()->json([
            'message' => __('messages.schedule.saved'),
        ]);
    }

    /** المواد المتاحة لهذه الشعبة — ما يُؤشَّر في الشبكة. */
    public function subjects(Section $section): JsonResponse
    {
        $this->authorize('view', $section);

        $subjects = Subject::query()
            ->where('grade_id', $section->grade_id)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json(['data' => $subjects]);
    }

    /** @return array<int, array<string, mixed>> */
    private function weekOf(Section $section): array
    {
        $rows = SectionDayHours::query()
            ->where('section_id', $section->id)
            ->get()
            ->keyBy(fn (SectionDayHours $h) => $h->day_of_week->value);

        return array_map(function (Weekday $day) use ($rows) {
            $hours = $rows->get($day->value);

            return [
                'day' => $day->value,
                'label' => $day->label(),
                'working' => (bool) $hours,
                'starts_at' => $hours ? substr((string) $hours->starts_at, 0, 5) : null,
                'ends_at' => $hours ? substr((string) $hours->ends_at, 0, 5) : null,
                'period_minutes' => $hours?->period_minutes,
                'break_minutes' => $hours?->break_minutes,
                'periods_count' => $hours ? count($hours->periods()) : 0,
            ];
        }, Weekday::schoolWeek());
    }
}
