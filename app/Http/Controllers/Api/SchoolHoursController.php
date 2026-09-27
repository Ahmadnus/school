<?php

namespace App\Http\Controllers\Api;

use App\Enums\Weekday;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\SchoolDayHours;
use App\Models\TeacherAvailability;
use App\Models\TimetableRun;
use App\Services\Timetable\TimeRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * دوام المعهد وطول حصّته — الحدّ الخارجي لكل جدول.
 *
 * ثلاثة أشياء تُضبط من هنا، وهي **ليست** شيئاً واحداً:
 *
 *  - **أيّام الدوام وساعاتها**: متى يفتح المعهد. يومٌ بلا صفٍّ يوم مغلق.
 *  - **طول الحصّة**: ستّون دقيقة في هذا المعهد، و`section_day_hours` تبقى
 *    قادرة على تجاوزه لشعبةٍ بعينها (صباحية ومسائية).
 *  - وهو **غير** تفرّغ الأستاذ، وغير دوام الشعبة.
 *
 * والشاشة يجب أن تقول الفرق صريحاً: خانة نصف الساعة في شاشة التفرّغ دقّةُ
 * تأشير، لا وعدٌ بأن الحصّة نصف ساعة.
 */
class SchoolHoursController extends Controller
{
    /** أسبوع المعهد كما يُعرَض: كل يوم عاملٌ أو مغلق، وساعاته. */
    public function show(Request $request): JsonResponse
    {
        $this->authorize('viewAll', TimetableRun::class);

        return response()->json(['data' => $this->week($request->user()->school)]);
    }

    /**
     * يضبط دوام يومٍ أو عدّة أيّام دفعة واحدة.
     *
     * الدفعة مقصودة: «الأحد إلى الخميس ١٢:٠٠ ← ١٨:٠٠» ضبطةٌ واحدة لا خمس —
     * وهي نفس العادة المتّبعة في ضبط دوام الشعبة.
     */
    public function update(Request $request): JsonResponse
    {
        $this->authorize('manage', TimetableRun::class);

        $data = $request->validate([
            'days' => ['required', 'array', 'min:1'],
            'days.*' => [Rule::in(array_column(Weekday::cases(), 'value'))],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
        ]);

        $school = $request->user()->school;

        foreach ($data['days'] as $day) {
            SchoolDayHours::updateOrCreate(
                ['school_id' => $school->id, 'day_of_week' => $day],
                ['starts_at' => $data['starts_at'], 'ends_at' => $data['ends_at']],
            );
        }

        return response()->json([
            'message' => __('timetable.messages.hours_saved'),
            'data' => $this->week($school->fresh()),
        ]);
    }

    /** يُغلق يوماً — يصير عطلةً للمعهد كلّه. */
    public function destroy(Request $request): JsonResponse
    {
        $this->authorize('manage', TimetableRun::class);

        $day = $request->integer('day');
        $school = $request->user()->school;

        SchoolDayHours::query()
            ->ofSchool($school->id)
            ->where('day_of_week', $day)
            ->delete();

        return response()->json([
            'message' => __('timetable.messages.hours_cleared'),
            'data' => $this->week($school->fresh()),
        ]);
    }

    /**
     * يضبط طول الحصّة المُعتمد للمعهد.
     *
     * منفصلٌ عن ضبط الساعات بقصد: ساعات الدوام تتغيّر كل فصل، وطول الحصّة
     * قرارٌ يُتَّخذ مرّة. وخلطهما في نموذجٍ واحد يجعل من يعدّل يوماً يعدّل
     * طول الحصّة سهواً.
     */
    public function setLessonMinutes(Request $request): JsonResponse
    {
        $this->authorize('manage', TimetableRun::class);

        $data = $request->validate([
            // الحدّ الأدنى خانةُ تأشيرٍ واحدة: حصّةٌ أقصر من دقّة التفرّغ لا
            // يستطيع أحدٌ أن يُعلن تفرّغه لها.
            'minutes' => ['required', 'integer', 'min:15', 'max:240'],
        ]);

        $school = $request->user()->school;
        $school->forceFill(['default_lesson_minutes' => $data['minutes']])->save();

        return response()->json([
            'message' => __('timetable.messages.lesson_minutes_saved', [
                'minutes' => $data['minutes'],
            ]),
            'data' => $this->week($school->fresh()),
        ]);
    }

    private function week(School $school): array
    {
        $rows = SchoolDayHours::query()
            ->ofSchool($school->id)
            ->get()
            ->keyBy(fn (SchoolDayHours $h) => $h->day_of_week->value);

        return [
            'lesson_minutes' => $school->lessonMinutes(),
            // تُعلَن صريحةً حتى تستطيع الشاشة أن تقول الفرق بلا أن تفترضه.
            'availability_slot_minutes' => TeacherAvailability::SLOT_MINUTES,
            'days' => array_map(function (Weekday $day) use ($rows, $school) {
                $hours = $rows->get($day->value);

                return [
                    'day' => $day->value,
                    'label' => $day->label(),
                    'working' => (bool) $hours,
                    'starts_at' => $hours ? TimeRange::format($hours->startMinute()) : null,
                    'ends_at' => $hours ? TimeRange::format($hours->endMinute()) : null,
                    // كم حصّة يتّسع لها اليوم — يُعرَض قبل الحفظ فلا يُفاجأ
                    // المدير بأن دواماً من ساعتين لا يكفي أربع حصص.
                    'lesson_capacity' => $hours
                        ? intdiv(
                            $hours->endMinute() - $hours->startMinute(),
                            $school->lessonMinutes(),
                        )
                        : 0,
                ];
            }, Weekday::schoolWeek()),
        ];
    }
}
