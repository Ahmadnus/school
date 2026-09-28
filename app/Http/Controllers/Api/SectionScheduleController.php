<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Enums\Weekday;
use App\Http\Controllers\Controller;
use App\Models\ScheduleSlot;
use App\Models\SchoolDayHours;
use App\Models\Section;
use App\Models\SectionDayHours;
use App\Models\Subject;
use App\Models\TeacherAssignment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

    /**
     * يملأ اليوم بمواد **مرتّبة**: الأولى للحصّة الأولى وهكذا.
     *
     * هذا هو الاستعمال الغالب: يؤشّر المستخدم رياضيات ثم فرنسي ثم إنجليزي
     * فتنزل على الحصص الثلاث الأولى بالترتيب نفسه — بلا أن يفتح كل حصّة.
     *
     * ما زاد عن عدد الحصص يُهمَل، وما بقي من حصص يُفرَّغ: القائمة المرسَلة
     * هي اليوم كلّه لا إضافة عليه.
     */
    public function fillGrid(Request $request, Section $section): JsonResponse
    {
        $this->authorize('update', $section);

        $data = $request->validate([
            'day' => ['required', Rule::in(array_column(Weekday::cases(), 'value'))],
            'term_id' => ['required', Rule::exists('terms', 'id')],
            'subject_ids' => ['present', 'array'],
            'subject_ids.*' => [Rule::exists('subjects', 'id')],
        ]);

        $hours = SectionDayHours::query()
            ->where('section_id', $section->id)
            ->where('day_of_week', $data['day'])
            ->first();

        if (! $hours) {
            return response()->json(['message' => __('messages.schedule.no_hours')], 422);
        }

        $periods = $hours->periods();
        $subjects = array_values($data['subject_ids']);

        $rows = [];
        foreach ($periods as $index => $period) {
            $rows[] = [
                'period_number' => $period['period_number'],
                'subject_id' => $subjects[$index] ?? null,
            ];
        }

        return $this->setGrid(
            $request->merge(['periods' => $rows]),
            $section,
        );
    }

    /**
     * الأسبوع كلّه في نداء واحد — الصفحة الواحدة التي يُبنى فيها الجدول.
     *
     * تصل جاهزة: دوام كل يوم، حصصه بأوقاتها المشتقّة، ما هو مُسنَد إليها،
     * ومواد الصفّ مع أساتذتها. فلا تفتح الشاشة نداءً لكل يوم ولا لكل مادة.
     */
    public function week(Request $request, Section $section): JsonResponse
    {
        $this->authorize('view', $section);

        $termId = $request->integer('term_id') ?: null;

        $hours = SectionDayHours::query()
            ->where('section_id', $section->id)
            ->get()
            ->keyBy(fn (SectionDayHours $h) => $h->day_of_week->value);

        $slots = ScheduleSlot::query()
            ->where('section_id', $section->id)
            ->when($termId, fn ($q) => $q->where('term_id', $termId))
            ->get()
            ->groupBy(fn (ScheduleSlot $s) => $s->day_of_week->value);

        $days = array_map(function (Weekday $day) use ($hours, $slots) {
            $dayHours = $hours->get($day->value);
            $assigned = ($slots->get($day->value) ?? collect())->keyBy('period_number');

            return [
                'day' => $day->value,
                'label' => $day->label(),
                'working' => (bool) $dayHours,
                'starts_at' => $dayHours ? substr((string) $dayHours->starts_at, 0, 5) : null,
                'ends_at' => $dayHours ? substr((string) $dayHours->ends_at, 0, 5) : null,
                'period_minutes' => $dayHours?->period_minutes,
                'break_minutes' => $dayHours?->break_minutes,
                'periods' => array_map(fn (array $period) => [
                    ...$period,
                    'subject_id' => $assigned->get($period['period_number'])?->subject_id,
                    'teacher_id' => $assigned->get($period['period_number'])?->staff_id,
                    'slot_id' => $assigned->get($period['period_number'])?->id,
                ], $dayHours ? $dayHours->periods() : []),
            ];
        }, Weekday::schoolWeek());

        return response()->json([
            'data' => [
                'section' => ['id' => $section->id, 'name' => $section->name],
                'term_id' => $termId,
                // دوام المعهد: القيم المقترحة لليوم الذي لم يُضبط بعد، حتى
                // لا تُخترع الشاشة ساعةً من عندها.
                'defaults' => $this->schoolDefaults($section),
                'days' => $days,
                'subjects' => $this->subjectOptions($section),
            ],
        ]);
    }

    /**
     * يحفظ الأسبوع كلّه — دواماً وحصصاً — في معاملة واحدة.
     *
     * الكل أو لا شيء بقصد: جدولٌ نصفه محفوظ ونصفه مرفوض أسوأ من جدولٍ لم
     * يُحفظ، لأنّ المستخدم يرى الخطأ ولا يعرف ما دخل منه وما لم يدخل.
     */
    public function setWeek(Request $request, Section $section): JsonResponse
    {
        $this->authorize('update', $section);

        $data = $request->validate([
            'term_id' => ['required', Rule::exists('terms', 'id')],
            'days' => ['present', 'array'],
            'days.*.day' => ['required', Rule::in(array_column(Weekday::cases(), 'value'))],
            'days.*.working' => ['nullable', 'boolean'],
            'days.*.starts_at' => ['required_if:days.*.working,true', 'nullable', 'date_format:H:i'],
            'days.*.ends_at' => ['required_if:days.*.working,true', 'nullable', 'date_format:H:i'],
            'days.*.period_minutes' => ['nullable', 'integer', 'min:5', 'max:240'],
            'days.*.break_minutes' => ['nullable', 'integer', 'min:0', 'max:120'],
            'days.*.periods' => ['nullable', 'array'],
            'days.*.periods.*.period_number' => ['required', 'integer', 'min:1', 'max:20'],
            'days.*.periods.*.subject_id' => ['nullable', 'integer'],
            'days.*.periods.*.teacher_id' => ['nullable', 'integer'],
        ]);

        $errors = $this->validateWeek($section, $data);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($data, $section) {
            foreach ($data['days'] as $row) {
                $day = (int) $row['day'];

                // يوم أُطفئ: يصير عطلة، ويذهب معه ما كان مجدولاً فيه —
                // حصصٌ بلا دوام أوقاتها معلّقة في الهواء.
                if (! ($row['working'] ?? false)) {
                    SectionDayHours::query()
                        ->where('section_id', $section->id)
                        ->where('day_of_week', $day)
                        ->delete();

                    ScheduleSlot::query()
                        ->where('section_id', $section->id)
                        ->where('term_id', $data['term_id'])
                        ->where('day_of_week', $day)
                        ->delete();

                    continue;
                }

                $hours = SectionDayHours::updateOrCreate(
                    ['section_id' => $section->id, 'day_of_week' => $day],
                    [
                        'starts_at' => $row['starts_at'],
                        'ends_at' => $row['ends_at'],
                        'period_minutes' => $row['period_minutes'] ?? $section->grade->school->lessonMinutes(),
                        'break_minutes' => $row['break_minutes'] ?? 0,
                    ],
                );

                $times = collect($hours->periods())->keyBy('period_number');
                $keep = [];

                foreach ($row['periods'] ?? [] as $period) {
                    $number = (int) $period['period_number'];
                    $time = $times->get($number);

                    if (! $time || empty($period['subject_id'])) {
                        continue;
                    }

                    $slot = ScheduleSlot::updateOrCreate([
                        'section_id' => $section->id,
                        'term_id' => $data['term_id'],
                        'day_of_week' => $day,
                        'period_number' => $number,
                    ], [
                        'subject_id' => $period['subject_id'],
                        'staff_id' => empty($period['teacher_id'])
                            ? $this->defaultTeacherFor($section, (int) $period['subject_id'])
                            : (int) $period['teacher_id'],
                        'starts_at' => $time['starts_at'],
                        'ends_at' => $time['ends_at'],
                    ]);

                    $keep[] = $slot->id;
                }

                // حصّة بلا مادة تُحذف بدل أن تبقى فارغة — نفس قاعدة `setGrid`،
                // ويدخل فيها ما تجاوز عدد الحصص بعد تقصير الدوام.
                ScheduleSlot::query()
                    ->where('section_id', $section->id)
                    ->where('term_id', $data['term_id'])
                    ->where('day_of_week', $day)
                    ->whereNotIn('id', $keep ?: [0])
                    ->delete();
            }
        });

        return response()->json([
            'message' => __('messages.schedule.saved'),
            'data' => $this->week($request, $section)->getData(true)['data'],
        ]);
    }

    /**
     * كل ما يُرفَض قبل أن يُكتب شيء.
     *
     * يُجمَع كاملاً بدل التوقّف عند أول خطأ: من يعبّئ أسبوعاً يريد قائمة ما
     * يصلحه، لا رسالةً واحدة يعيد الحفظ بعدها ليجد الثانية.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, array<int, string>>
     */
    private function validateWeek(Section $section, array $data): array
    {
        $errors = [];

        $subjectIds = Subject::query()
            ->where('grade_id', $section->grade_id)
            ->pluck('id')
            ->all();

        $schoolId = $section->grade->school_id;

        $teacherIds = User::query()
            ->where('school_id', $schoolId)
            ->whereIn('role', [UserRole::Teacher, UserRole::Admin, UserRole::SuperAdmin])
            ->pluck('id')
            ->all();

        // إسنادات هذه الشعبة: من يجوز أن يدرّس أي مادة فيها.
        $allowed = TeacherAssignment::query()
            ->where('section_id', $section->id)
            ->get()
            ->groupBy('subject_id')
            ->map(fn ($rows) => $rows->pluck('staff_id')->filter()->unique()->values()->all());

        $teacherLoad = [];

        foreach ($data['days'] as $index => $row) {
            $day = (int) $row['day'];
            $key = "days.{$index}";

            if (! ($row['working'] ?? false)) {
                continue;
            }

            $start = SchoolDayHours::toMinutes($row['starts_at']);
            $end = SchoolDayHours::toMinutes($row['ends_at']);

            if ($end <= $start) {
                $errors["{$key}.ends_at"][] = __('messages.schedule.end_before_start');

                continue;
            }

            $length = (int) ($row['period_minutes'] ?? 0);
            $capacity = $length > 0
                ? count((new SectionDayHours([
                    'starts_at' => $row['starts_at'],
                    'ends_at' => $row['ends_at'],
                    'period_minutes' => $length,
                    'break_minutes' => (int) ($row['break_minutes'] ?? 0),
                ]))->periods())
                : 0;

            if ($capacity === 0) {
                $errors["{$key}.period_minutes"][] = __('messages.schedule.no_periods_fit');

                continue;
            }

            $seen = [];

            foreach ($row['periods'] ?? [] as $slotIndex => $period) {
                $number = (int) $period['period_number'];
                $slotKey = "{$key}.periods.{$slotIndex}";

                if (isset($seen[$number])) {
                    $errors["{$slotKey}.period_number"][] = __('messages.schedule.duplicate_period');

                    continue;
                }
                $seen[$number] = true;

                if ($number > $capacity) {
                    $errors["{$slotKey}.period_number"][] = __('messages.schedule.period_out_of_range', [
                        'count' => $capacity,
                    ]);

                    continue;
                }

                if (empty($period['subject_id'])) {
                    continue;
                }

                $subjectId = (int) $period['subject_id'];

                if (! in_array($subjectId, $subjectIds, true)) {
                    $errors["{$slotKey}.subject_id"][] = __('messages.schedule.subject_not_in_grade');

                    continue;
                }

                $teacherId = empty($period['teacher_id'])
                    ? $this->defaultTeacherFor($section, $subjectId)
                    : (int) $period['teacher_id'];

                if ($teacherId === null) {
                    continue;
                }

                if (! in_array($teacherId, $teacherIds, true)) {
                    $errors["{$slotKey}.teacher_id"][] = __('messages.schedule.teacher_not_staff');

                    continue;
                }

                // إسنادٌ قائم للمادة في هذه الشعبة يُلزِم: لو كان لها أستاذ
                // معيَّن فلا يُدَسّ غيره من الجدول ويفترق المصدران.
                $permitted = $allowed->get($subjectId);

                if ($permitted !== null && $permitted !== [] && ! in_array($teacherId, $permitted, true)) {
                    $errors["{$slotKey}.teacher_id"][] = __('messages.schedule.teacher_not_assigned');

                    continue;
                }

                if (isset($teacherLoad[$teacherId][$day][$number])) {
                    $errors["{$slotKey}.teacher_id"][] = __('messages.schedule.teacher_twice_in_period');

                    continue;
                }
                $teacherLoad[$teacherId][$day][$number] = true;

                // تعارضٌ مع شعبة أخرى في الفصل نفسه — أستاذ واحد لا يكون في
                // صفّين في الوقت ذاته.
                $clash = ScheduleSlot::query()
                    ->where('staff_id', $teacherId)
                    ->where('term_id', $data['term_id'])
                    ->where('day_of_week', $day)
                    ->where('period_number', $number)
                    ->where('section_id', '!=', $section->id)
                    ->with('section')
                    ->first();

                if ($clash !== null) {
                    $errors["{$slotKey}.teacher_id"][] = __('messages.schedule.teacher_busy', [
                        'section' => $clash->section?->name ?? '—',
                    ]);
                }
            }
        }

        return $errors;
    }

    /**
     * أستاذ المادة في هذه الشعبة إن كان واحداً لا غير.
     *
     * الواحد يُختار وحده فلا يُسأل عنه المستخدم؛ والاثنان يبقى اختيارهما له —
     * فالتخمين بينهما يُنشئ جدولاً يبدو صحيحاً وهو خطأ.
     */
    private function defaultTeacherFor(Section $section, int $subjectId): ?int
    {
        $staff = TeacherAssignment::query()
            ->where('section_id', $section->id)
            ->where('subject_id', $subjectId)
            ->pluck('staff_id')
            ->filter()
            ->unique()
            ->values();

        return $staff->count() === 1 ? (int) $staff->first() : null;
    }

    /**
     * مواد الصفّ ومعها أساتذتها المسنَدون لهذه الشعبة.
     *
     * @return array<int, array<string, mixed>>
     */
    private function subjectOptions(Section $section): array
    {
        $assignments = TeacherAssignment::query()
            ->where('section_id', $section->id)
            ->with('teacher')
            ->get()
            ->groupBy('subject_id');

        return Subject::query()
            ->where('grade_id', $section->grade_id)
            ->orderBy('name')
            ->get(['id', 'name', 'periods_per_week'])
            ->map(function (Subject $subject) use ($assignments) {
                $teachers = ($assignments->get($subject->id) ?? collect())
                    ->pluck('teacher')
                    ->filter()
                    ->unique('id')
                    ->values();

                return [
                    'id' => $subject->id,
                    'name' => $subject->name,
                    'periods_per_week' => $subject->periods_per_week,
                    'teachers' => $teachers
                        ->map(fn (User $t) => ['id' => $t->id, 'full_name' => $t->full_name])
                        ->all(),
                    // أستاذٌ واحد يُسنَد وحده — الشاشة تعرضه ولا تسأل عنه.
                    'default_teacher_id' => $teachers->count() === 1 ? $teachers->first()->id : null,
                ];
            })
            ->all();
    }

    /**
     * ما يقترحه دوام المعهد على يومٍ لم يُضبط بعد.
     *
     * @return array<string, mixed>
     */
    private function schoolDefaults(Section $section): array
    {
        $school = $section->grade->school;

        $rows = SchoolDayHours::query()
            ->ofSchool($school->id)
            ->get()
            ->keyBy(fn (SchoolDayHours $h) => $h->day_of_week->value);

        return [
            'period_minutes' => $school->lessonMinutes(),
            'break_minutes' => 0,
            'days' => array_map(function (Weekday $day) use ($rows) {
                $hours = $rows->get($day->value);

                return [
                    'day' => $day->value,
                    // يومٌ مغلق في المعهد لا يُقترح للشعبة.
                    'school_working' => $hours !== null,
                    'starts_at' => $hours ? substr((string) $hours->starts_at, 0, 5) : null,
                    'ends_at' => $hours ? substr((string) $hours->ends_at, 0, 5) : null,
                ];
            }, Weekday::schoolWeek()),
        ];
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
