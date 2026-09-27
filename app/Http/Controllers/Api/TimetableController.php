<?php

namespace App\Http\Controllers\Api;

use App\Enums\TimetableRunStatus;
use App\Enums\Weekday;
use App\Http\Controllers\Controller;
use App\Models\ScheduleSlot;
use App\Models\School;
use App\Models\Section;
use App\Models\Term;
use App\Models\TimetableRun;
use App\Models\User;
use App\Services\Timetable\TimeRange;
use App\Services\Timetable\TimetableAnalyzer;
use App\Services\Timetable\TimetableConstraints;
use App\Services\Timetable\TimetableGenerator;
use App\Services\Timetable\TimetableValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * تحليل الجدول وتوليده وعرضه.
 *
 * الترتيب مفروض ولا يُختصَر: **تحليل ثمّ توليد**. والتحليل يُنادى وحده من زرّ
 * «تحليل الجدول»، ويُنادى ثانيةً داخل المولّد قبل أن يبدأ — فلا يُنفَق وقت
 * تراجُعٍ على قيدٍ مستحيل من أوّله، ولا يُكتب جدولٌ ناقص أبداً.
 *
 * والحساب كلّه في `app/Services/Timetable`: هذا المتحكّم ينقل الطلب ويُعيد
 * الردّ، ولا قاعدةَ عملٍ فيه.
 */
class TimetableController extends Controller
{
    /**
     * تقرير الجدوى: ما يحتاجه كل طرف وما يملكه، وأسباب الاستحالة إن وُجدت.
     *
     * لا يكتب شيئاً في `schedule_slots` — يُسجّل محاولةً بحالة «مُحلَّل» فقط،
     * حتى يبقى التقرير مقروءاً بعد إغلاق الشاشة.
     */
    public function analyze(Request $request): JsonResponse
    {
        $this->authorize('manage', TimetableRun::class);

        [$school, $term] = $this->context($request);
        $constraints = TimetableConstraints::load($school, $term);
        $analyzer = TimetableAnalyzer::for($constraints);

        $run = TimetableRun::create([
            'school_id' => $school->id,
            'term_id' => $term->id,
            'status' => $analyzer->isFeasible()
                ? TimetableRunStatus::Analyzed
                : TimetableRunStatus::Infeasible,
            'requested_by' => $request->user()->id,
            'lesson_minutes' => $constraints->defaultLessonMinutes,
            'stats' => $analyzer->stats(),
            'conflicts' => $analyzer->conflictsArray(),
            'lessons_required' => $analyzer->lessonsRequired(),
            'lessons_placed' => 0,
        ]);

        return response()->json([
            'message' => $analyzer->isFeasible()
                ? __('timetable.messages.analyzed')
                : __('timetable.messages.infeasible'),
            'data' => [
                ...$this->runPayload($run),
                'feasible' => $analyzer->isFeasible(),
                'assignments' => $analyzer->assignmentRows(),
            ],
        ]);
    }

    /**
     * يولّد الجدول — أو لا يولّد شيئاً ويقول لماذا.
     *
     * إعادة التوليد تمرّ من هنا نفسه: تحذف حصص المحاولة السابقة **المولَّدة**
     * وحدها، ولا تمسّ ما بناه المستخدم بيده. وهذا فرقٌ يحمي جدولاً قائماً في
     * الإنتاج من ضغطةِ زرّ.
     */
    public function generate(Request $request): JsonResponse
    {
        $this->authorize('manage', TimetableRun::class);

        [$school, $term] = $this->context($request);
        $constraints = TimetableConstraints::load($school, $term);
        $analyzer = TimetableAnalyzer::for($constraints);

        $run = (new TimetableGenerator($constraints, $analyzer))->run($school, $request->user());

        return response()->json([
            'message' => $run->succeeded()
                ? __('timetable.messages.generated', ['count' => $run->lessons_placed])
                : __('timetable.messages.infeasible'),
            'data' => [
                ...$this->runPayload($run),
                'feasible' => $run->succeeded(),
            ],
        ], $run->succeeded() ? 200 : 422);
    }

    /** آخر محاولة لهذا الفصل — تقريرها وحصصها. */
    public function latest(Request $request): JsonResponse
    {
        $this->authorize('viewAll', TimetableRun::class);

        [$school, $term] = $this->context($request);

        $run = TimetableRun::query()
            ->ofSchool($school->id)
            ->where('term_id', $term->id)
            ->latest('id')
            ->first();

        if (! $run) {
            return response()->json([
                'message' => __('timetable.messages.no_run'),
                'data' => null,
            ]);
        }

        return response()->json(['data' => $this->runPayload($run)]);
    }

    /**
     * الجدول كاملاً: كل الشعب وكل الأساتذة، مرتَّباً للشبكة.
     *
     * يُرشَّح اختياريّاً بيومٍ واحد — «عرض بحسب اليوم» في الشاشة.
     */
    public function all(Request $request): JsonResponse
    {
        $this->authorize('viewAll', TimetableRun::class);

        [$school, $term] = $this->context($request);

        $slots = $this->slots($school->id, $term->id, $request);

        return response()->json([
            'data' => $this->grid($slots, $school->lessonMinutes()),
        ]);
    }

    /** جدول أستاذ — وهو ما يراه هو في تطبيقه. */
    public function forTeacher(Request $request, User $user): JsonResponse
    {
        $this->authorize('viewTimetable', $user);

        [$school, $term] = $this->context($request);

        $slots = $this->slots($school->id, $term->id, $request)
            ->where('staff_id', $user->id)
            ->values();

        return response()->json([
            'data' => [
                'teacher' => ['id' => $user->id, 'name' => $user->full_name],
                ...$this->grid($slots, $school->lessonMinutes()),
            ],
        ]);
    }

    /** جدول شعبة. */
    public function forSection(Request $request, Section $section): JsonResponse
    {
        $this->authorize('viewTimetable', $section);

        [$school, $term] = $this->context($request);

        $slots = $this->slots($school->id, $term->id, $request)
            ->where('section_id', $section->id)
            ->values();

        $section->loadMissing('grade');

        return response()->json([
            'data' => [
                'section' => [
                    'id' => $section->id,
                    'name' => $section->name,
                    'grade' => $section->grade?->name,
                ],
                ...$this->grid($slots, $school->lessonMinutes()),
            ],
        ]);
    }

    /** جدولي — ما يفتحه الأستاذ في تطبيقه بلا أن يختار نفسه من قائمة. */
    public function mine(Request $request): JsonResponse
    {
        return $this->forTeacher($request, $request->user());
    }

    /**
     * ينقل حصّة يدويّاً — ولا يحفظ تعارضاً صلباً أبداً.
     *
     * الإدارة تعدّل، لكن **لا صلاحيةَ تُبيح تعارضاً**: أستاذٌ في مكانين أو
     * شعبةٌ في درسين خطأُ بياناتٍ لا قرارُ إدارة. فيُفحَص الموضع قبل الحفظ،
     * ويُعاد التقرير كاملاً ليُقرأ السبب.
     */
    public function move(Request $request, ScheduleSlot $slot): JsonResponse
    {
        $this->authorize('manage', TimetableRun::class);

        $slot->loadMissing('section.grade');

        abort_unless(
            $slot->section?->grade?->school_id === $request->user()->school_id,
            403,
        );

        $data = $request->validate([
            'day_of_week' => ['required', Rule::in(
                array_column(Weekday::cases(), 'value'),
            )],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
            'staff_id' => ['nullable', 'integer'],
        ]);

        $staffId = array_key_exists('staff_id', $data)
            ? $data['staff_id']
            : $slot->staff_id;

        $conflicts = TimetableValidator::validatePlacement(
            sectionId: $slot->section_id,
            termId: $slot->term_id,
            subjectId: $slot->subject_id,
            staffId: $staffId,
            day: (int) $data['day_of_week'],
            startsAt: $data['starts_at'],
            endsAt: $data['ends_at'],
            ignoreSlotId: $slot->id,
        );

        if ($conflicts !== []) {
            return response()->json([
                'message' => __('timetable.messages.move_rejected'),
                'data' => ['conflicts' => $conflicts],
            ], 422);
        }

        $slot->update([
            'day_of_week' => $data['day_of_week'],
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'staff_id' => $staffId,
        ]);

        return response()->json([
            'message' => __('timetable.messages.slot_moved'),
            'data' => $this->slotPayload($slot->fresh(['subject', 'teacher', 'section.grade'])),
        ]);
    }

    /** يمحو الجدول المولَّد — ويُبقي ما بُني يدويّاً. */
    public function clear(Request $request): JsonResponse
    {
        $this->authorize('manage', TimetableRun::class);

        [$school, $term] = $this->context($request);

        DB::transaction(function () use ($school, $term) {
            ScheduleSlot::query()
                ->where('term_id', $term->id)
                ->generated()
                ->whereHas('timetableRun', fn ($q) => $q->where('school_id', $school->id))
                ->delete();
        });

        return response()->json(['message' => __('timetable.messages.cleared')]);
    }

    // ------------------------------------------------------------- internals

    /** @return array{0: School, 1: Term} */
    private function context(Request $request): array
    {
        $school = $request->user()->school;

        $term = $request->filled('term_id')
            ? Term::query()
                ->whereKey($request->integer('term_id'))
                ->whereHas('academicYear', fn ($q) => $q->where('school_id', $school->id))
                ->firstOrFail()
            : Term::currentFor($school->id);

        abort_if($term === null, 422, __('timetable.messages.no_term'));

        return [$school, $term];
    }

    /** @return Collection<int, ScheduleSlot> */
    private function slots(int $schoolId, int $termId, Request $request)
    {
        return ScheduleSlot::query()
            ->ofSchool($schoolId)
            ->where('term_id', $termId)
            ->when(
                $request->has('day_of_week'),
                fn ($q) => $q->where('day_of_week', $request->integer('day_of_week')),
            )
            ->with(['subject', 'teacher', 'section.grade'])
            ->ordered()
            ->get();
    }

    /**
     * الحصص مرتَّبةً للشبكة: صفوفُ أوقاتٍ × أعمدةُ أيّام.
     *
     * الترتيب يُبنى في الخادم لا في العميل: الأوقات المتاحة تختلف من يوم إلى
     * يوم (السبت ١٠:٠٠ والاثنين ١٢:٠٠)، فصفوف الشبكة هي اتّحاد أوقات البدء
     * كلّها — وحسابها في مكانين يجعل الشاشتين تختلفان.
     */
    private function grid($slots, int $lessonMinutes): array
    {
        $starts = $slots
            ->map(fn (ScheduleSlot $s) => TimeRange::minutes($s->starts_at))
            ->unique()
            ->sort()
            ->values();

        $days = Weekday::schoolWeek();

        $rows = $starts->map(function (int $start) use ($slots, $days) {
            $cells = [];

            foreach ($days as $day) {
                $at = $slots->first(
                    fn (ScheduleSlot $s) => $s->day_of_week->value === $day->value
                        && TimeRange::minutes($s->starts_at) === $start,
                );

                $cells[(string) $day->value] = $at ? $this->slotPayload($at) : null;
            }

            return [
                'starts_at' => TimeRange::format($start),
                'cells' => $cells,
            ];
        });

        return [
            'lesson_minutes' => $lessonMinutes,
            'days' => array_map(
                fn (Weekday $d) => ['day' => $d->value, 'label' => $d->label()],
                $days,
            ),
            'rows' => $rows->values()->all(),
            'slots' => $slots->map(fn (ScheduleSlot $s) => $this->slotPayload($s))->values()->all(),
            'total' => $slots->count(),
        ];
    }

    private function slotPayload(ScheduleSlot $slot): array
    {
        return [
            'id' => $slot->id,
            'day_of_week' => $slot->day_of_week->value,
            'starts_at' => TimeRange::format(TimeRange::minutes($slot->starts_at)),
            'ends_at' => TimeRange::format(TimeRange::minutes($slot->ends_at)),
            'period_number' => $slot->period_number,
            'subject' => $slot->subject ? [
                'id' => $slot->subject->id,
                'name' => $slot->subject->name,
            ] : null,
            'teacher' => $slot->teacher ? [
                'id' => $slot->teacher->id,
                'name' => $slot->teacher->full_name,
            ] : null,
            'section' => $slot->section ? [
                'id' => $slot->section->id,
                'name' => $slot->section->name,
                'grade' => $slot->section->grade?->name,
            ] : null,
            // يُعلَن صريحاً: المولَّد تمحوه إعادة التوليد، واليدويّ لا.
            'generated' => $slot->timetable_run_id !== null,
        ];
    }

    private function runPayload(TimetableRun $run): array
    {
        return [
            'id' => $run->id,
            'status' => $run->status->value,
            'status_label' => $run->status->label(),
            'term_id' => $run->term_id,
            'lesson_minutes' => $run->lesson_minutes,
            'lessons_required' => $run->lessons_required,
            'lessons_placed' => $run->lessons_placed,
            'stats' => $run->stats,
            'conflicts' => $run->conflicts ?? [],
            'created_at' => $run->created_at?->toIso8601String(),
        ];
    }
}
