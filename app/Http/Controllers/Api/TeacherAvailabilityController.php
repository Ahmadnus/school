<?php

namespace App\Http\Controllers\Api;

use App\Enums\Weekday;
use App\Http\Controllers\Controller;
use App\Models\TeacherAvailability;
use App\Models\User;
use App\Services\Timetable\TimeRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * تفرّغ الأستاذ: متى يستطيع هو أن يُدرّس، لكل يوم على حدة.
 *
 * **نصف الساعة دقّة التأشير لا طول الحصّة.** الشبكة ٠٨:٠٠ ← ١٨:٠٠ بأربعين
 * خانة، والحصّة ستّون دقيقة في هذا المعهد. فمن أشّر ١١:٣٠ ← ١٣:٠٠ أعطى
 * المولّد ابتداءين ممكنين (١١:٣٠ و١٢:٠٠)، لا ثلاث حصص.
 *
 * والمُدَد تُخزَّن مدمَجة: الخانات المتّصلة صفٌّ واحد. أستاذٌ متفرّغ يوماً كاملاً
 * يكلّف صفّاً بدل عشرين، والمولّد يقارن مدًى بمدًى.
 */
class TeacherAvailabilityController extends Controller
{
    /** أسبوع التفرّغ كما يُعرَض في الشاشة: كل يوم بمُدَده وخاناته. */
    public function show(User $user): JsonResponse
    {
        $this->authorize('viewFor', [TeacherAvailability::class, $user]);

        return response()->json(['data' => $this->weekOf($user)]);
    }

    /**
     * يضبط تفرّغ يومٍ: قائمة مُدَد تُستبدَل بالقائمة القديمة كلّها.
     *
     * الاستبدال لا الإضافة: الشاشة ترسل حال اليوم كما صار، لا فرقاً عنه.
     * الإضافة كانت تجعل إزالة خانةٍ مستحيلة.
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $this->authorize('manageFor', [TeacherAvailability::class, $user]);

        $data = $request->validate([
            'day' => ['required', Rule::in(array_column(Weekday::cases(), 'value'))],
            'ranges' => ['present', 'array', 'max:20'],
            'ranges.*.starts_at' => ['required', 'date_format:H:i'],
            'ranges.*.ends_at' => ['required', 'date_format:H:i', 'after:ranges.*.starts_at'],
        ]);

        $ranges = $this->normalise($data['ranges']);

        if ($ranges === null) {
            return response()->json([
                'message' => __('timetable.messages.grid_misaligned', [
                    'minutes' => TeacherAvailability::SLOT_MINUTES,
                ]),
            ], 422);
        }

        DB::transaction(function () use ($user, $data, $ranges) {
            TeacherAvailability::query()
                ->where('staff_id', $user->id)
                ->where('day_of_week', $data['day'])
                ->delete();

            foreach ($ranges as $range) {
                TeacherAvailability::create([
                    'staff_id' => $user->id,
                    'day_of_week' => $data['day'],
                    'starts_at' => TimeRange::format($range->start),
                    'ends_at' => TimeRange::format($range->end),
                ]);
            }
        });

        return response()->json([
            'message' => $ranges === []
                ? __('timetable.messages.availability_cleared')
                : __('timetable.messages.availability_saved'),
            'data' => $this->weekOf($user),
        ]);
    }

    /** يُفرغ تفرّغ يوم — يصير الأستاذ غير متفرّغ فيه. */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorize('manageFor', [TeacherAvailability::class, $user]);

        $day = $request->integer('day');

        TeacherAvailability::query()
            ->where('staff_id', $user->id)
            ->where('day_of_week', $day)
            ->delete();

        return response()->json([
            'message' => __('timetable.messages.availability_cleared'),
            'data' => $this->weekOf($user),
        ]);
    }

    /**
     * يفحص المُدَد ويدمج المتّصل منها.
     *
     * ثلاثة أشياء تُضبط هنا لا في العميل: الحدود على شبكة نصف الساعة، والمدى
     * داخل ٠٨:٠٠ ← ١٨:٠٠، والمتداخل يُدمَج. آخرها هو ما يمنع الفهرس الفريد
     * من الانفجار حين يؤشّر أحدٌ خانةً مرّتين.
     *
     * @return list<TimeRange>|null `null` إن لم تكن الحدود على الشبكة
     */
    private function normalise(array $rows): ?array
    {
        $gridStart = TimeRange::minutes(TeacherAvailability::GRID_START);
        $gridEnd = TimeRange::minutes(TeacherAvailability::GRID_END);
        $step = TeacherAvailability::SLOT_MINUTES;

        $ranges = [];

        foreach ($rows as $row) {
            $range = TimeRange::of($row['starts_at'], $row['ends_at']);

            if ($range->start % $step !== 0 || $range->end % $step !== 0) {
                return null;
            }

            // ما خرج عن الشبكة يُقصّ لا يُرفَض: الشاشة لا تعرض خارجها أصلاً،
            // ومدًى يلمس الحدّ لا ينبغي أن يُفشل الحفظ كلّه.
            $clipped = $range->intersect(new TimeRange($gridStart, $gridEnd));

            if ($clipped !== null && ! $clipped->isEmpty()) {
                $ranges[] = $clipped;
            }
        }

        usort($ranges, fn (TimeRange $a, TimeRange $b) => $a->start <=> $b->start);

        $merged = [];

        foreach ($ranges as $range) {
            $last = $merged === [] ? null : $merged[count($merged) - 1];

            // الملامسة تُدمَج كالتداخل: «٠٨:٠٠–٠٩:٠٠» و«٠٩:٠٠–١٠:٠٠» مدًى
            // واحد من ساعتين، وإبقاؤهما مدَيين يمنع حصّةً تعبر الحدّ بينهما.
            if ($last !== null && $range->start <= $last->end) {
                $merged[count($merged) - 1] = new TimeRange(
                    $last->start,
                    max($last->end, $range->end),
                );

                continue;
            }

            $merged[] = $range;
        }

        return $merged;
    }

    /** @return array<int, array<string, mixed>> */
    private function weekOf(User $user): array
    {
        $rows = TeacherAvailability::query()
            ->where('staff_id', $user->id)
            ->orderBy('starts_at')
            ->get()
            ->groupBy(fn (TeacherAvailability $a) => $a->day_of_week->value);

        return [
            'staff_id' => $user->id,
            'teacher' => $user->full_name,
            'slot_minutes' => TeacherAvailability::SLOT_MINUTES,
            'grid_start' => TeacherAvailability::GRID_START,
            'grid_end' => TeacherAvailability::GRID_END,
            'days' => array_map(function (Weekday $day) use ($rows) {
                $ranges = $rows->get($day->value, collect());

                return [
                    'day' => $day->value,
                    'label' => $day->label(),
                    'available' => $ranges->isNotEmpty(),
                    'ranges' => $ranges->map(fn (TeacherAvailability $a) => [
                        'starts_at' => TimeRange::format($a->startMinute()),
                        'ends_at' => TimeRange::format($a->endMinute()),
                    ])->values()->all(),
                    'minutes' => $ranges->sum(fn (TeacherAvailability $a) => $a->minutes()),
                ];
            }, Weekday::schoolWeek()),
        ];
    }
}
