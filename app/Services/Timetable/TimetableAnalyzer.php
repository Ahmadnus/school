<?php

namespace App\Services\Timetable;

use App\Models\TeacherAssignment;

/**
 * هل الجدول ممكن أصلاً؟ — ويُسأل **قبل** التوليد.
 *
 * التوليد الذي يفشل بعد أن جرّب كل الاحتمالات يقول «مستحيل» ولا يقول لماذا.
 * والمدير لا يستطيع أن يفعل شيئاً بهذه الكلمة. فالتحليل يقيس السعة ويعدّ
 * الأماكن الممكنة ويسمّي من يمنع، بلغةٍ فيها رقمان: ما يحتاجه، وما يملكه.
 *
 *     «الأستاذ أحمد يحتاج ٨ حصص، وتفرّغه لا يتّسع إلاّ لـ٦.»
 *
 * ويُشغَّل مرّتين: مرّة وحده (زرّ «تحليل الجدول»)، ومرّة داخل المولّد قبل أن
 * يبدأ — فلا يُنفَق وقتُ تراجُعٍ على قيدٍ مستحيل من أوّله.
 */
class TimetableAnalyzer
{
    /** @var list<TimetableConflict> */
    private array $conflicts = [];

    /** @var list<LessonRequirement> */
    private array $requirements = [];

    private array $stats = [];

    public function __construct(private readonly TimetableConstraints $constraints) {}

    public static function for(TimetableConstraints $constraints): self
    {
        return (new self($constraints))->run();
    }

    public function run(): self
    {
        $this->conflicts = [];
        $this->requirements = [];

        if ($this->checkSchoolIsOpen() === false) {
            $this->stats = $this->summarise();

            return $this;
        }

        $this->buildRequirements();
        $this->checkSectionWindows();
        $this->checkTeacherAvailabilityExists();
        $this->checkPlacementsExist();
        $this->checkTeacherCapacity();
        $this->checkSectionCapacity();

        $this->stats = $this->summarise();

        return $this;
    }

    public function isFeasible(): bool
    {
        return $this->conflicts === [];
    }

    /** @return list<LessonRequirement> */
    public function requirements(): array
    {
        return $this->requirements;
    }

    /** @return list<TimetableConflict> */
    public function conflicts(): array
    {
        return $this->conflicts;
    }

    public function conflictsArray(): array
    {
        return array_map(fn (TimetableConflict $c) => $c->toArray(), $this->conflicts);
    }

    public function stats(): array
    {
        return $this->stats;
    }

    public function lessonsRequired(): int
    {
        return count($this->requirements);
    }

    // ---------------------------------------------------------------- checks

    /** معهدٌ بلا يوم دوامٍ واحد لا يُجدوَل، ولا معنى لقياس شيء بعد ذلك. */
    private function checkSchoolIsOpen(): bool
    {
        if ($this->constraints->openDays() !== []) {
            return true;
        }

        $this->conflicts[] = new TimetableConflict('school_has_no_working_days');

        return false;
    }

    /**
     * يفرد الإسنادات إلى حصصٍ مفردة، ويحسب لكلٍّ أماكنه الممكنة.
     *
     * إسنادٌ بصفر حصص خطأُ إعدادٍ لا فراغ: أحدٌ أسند المادة ولم يقل كم حصّة،
     * والمادة نفسها بلا `periods_per_week`. فيُعلَن بدل أن يسقط صامتاً.
     */
    private function buildRequirements(): void
    {
        foreach ($this->constraints->assignments as $assignment) {
            $count = $assignment->lessonsPerWeek();

            if ($count < 1) {
                $this->conflicts[] = new TimetableConflict(
                    'assignment_has_no_lessons',
                    [
                        'teacher' => $this->constraints->teacherName($assignment->staff_id),
                        'subject' => $this->constraints->subjectName($assignment->subject_id),
                        'section' => $this->constraints->sectionName($assignment->section_id),
                    ],
                    $assignment->staff_id,
                    $assignment->section_id,
                    $assignment->subject_id,
                );

                continue;
            }

            $minutes = $this->constraints->lessonMinutesFor($assignment->section_id);

            for ($index = 0; $index < $count; $index++) {
                $requirement = new LessonRequirement(
                    staffId: $assignment->staff_id,
                    sectionId: $assignment->section_id,
                    subjectId: $assignment->subject_id,
                    lessonMinutes: $minutes,
                    index: $index,
                    totalForAssignment: $count,
                );

                $requirement->candidates = $this->candidatesFor($requirement);
                $this->requirements[] = $requirement;
            }
        }
    }

    /**
     * كل موضعٍ تصحّ فيه هذه الحصّة، بلا نظرٍ إلى بقيّة الحصص.
     *
     * التعارض بين حصّتين شأنُ المولّد؛ هنا تُفحَص القيود الثابتة وحدها: دوام
     * المعهد ∩ دوام الشعبة ∩ تفرّغ الأستاذ، وما حجزته حصّةٌ يدويّة قائمة.
     *
     * @return list<array{day:int,range:TimeRange,period:int}>
     */
    private function candidatesFor(LessonRequirement $requirement): array
    {
        $candidates = [];

        foreach ($this->constraints->openDays() as $weekday) {
            $day = $weekday->value;
            $window = $this->constraints->windowFor($requirement->sectionId, $day);

            if ($window === null || $window->isEmpty()) {
                continue;
            }

            foreach ($this->constraints->startsWithin($window, $requirement->lessonMinutes) as $start) {
                $lesson = new TimeRange($start, $start + $requirement->lessonMinutes);

                if (! $this->constraints->teacherIsFree($requirement->staffId, $day, $lesson)) {
                    continue;
                }

                if ($this->constraints->sectionBusyManually($requirement->sectionId, $day, $lesson)) {
                    continue;
                }

                if ($this->constraints->teacherBusyManually($requirement->staffId, $day, $lesson)) {
                    continue;
                }

                $period = $this->periodNumber($window, $start);

                if ($this->constraints->periodTaken($requirement->sectionId, $day, $period)) {
                    continue;
                }

                $candidates[] = ['day' => $day, 'range' => $lesson, 'period' => $period];
            }
        }

        return $candidates;
    }

    /**
     * رقم الحصّة: رتبتها على شبكة نصف الساعة من بداية نافذة يومها.
     *
     * الفهرس الفريد في `schedule_slots` مبنيّ على
     * (شعبة، فصل، يوم، رقم حصّة)، فالرقم يجب أن يكون دالّةً للوقت لا عدّاداً
     * — عدّادٌ يُنتج الرقم نفسه لوقتين مختلفين عند إعادة التوليد.
     */
    private function periodNumber(TimeRange $window, int $start): int
    {
        return intdiv($start - $window->start, TimetableConstraints::GRANULARITY) + 1;
    }

    /** شعبةٌ عليها إسنادات ولا نافذة لها في الأسبوع كلّه. */
    private function checkSectionWindows(): void
    {
        foreach ($this->constraints->sectionIds() as $sectionId) {
            $hasWindow = false;

            foreach ($this->constraints->openDays() as $weekday) {
                $window = $this->constraints->windowFor($sectionId, $weekday->value);

                if ($window !== null && ! $window->isEmpty()) {
                    $hasWindow = true;
                    break;
                }
            }

            if (! $hasWindow) {
                $this->conflicts[] = new TimetableConflict(
                    'section_has_no_window',
                    ['section' => $this->constraints->sectionName($sectionId)],
                    sectionId: $sectionId,
                );
            }
        }
    }

    /**
     * أستاذٌ يُدرّس ولم يضبط تفرّغه بعد.
     *
     * هذا أشيع سببٍ للفشل، ويُقال بلغته: «لم يضبط تفرّغه» لا «لا خانات
     * متاحة» — الأولى يُتابعها المدير بمكالمة، والثانية لا يعرف ما يفعل بها.
     */
    private function checkTeacherAvailabilityExists(): void
    {
        $staffIds = $this->constraints->assignments
            ->pluck('staff_id')->unique()->sort()->values();

        foreach ($staffIds as $staffId) {
            if (empty($this->constraints->availability[$staffId])) {
                $this->conflicts[] = new TimetableConflict(
                    'teacher_has_no_availability',
                    ['teacher' => $this->constraints->teacherName($staffId)],
                    staffId: $staffId,
                );
            }
        }
    }

    /** إسنادٌ لا موضع له أصلاً — التقاطُع خالٍ قبل أي تعارض. */
    private function checkPlacementsExist(): void
    {
        $reported = [];

        foreach ($this->requirements as $requirement) {
            if ($requirement->candidates !== []) {
                continue;
            }

            $key = $requirement->assignmentKey();

            if (isset($reported[$key])) {
                continue;
            }

            $reported[$key] = true;

            // أستاذٌ بلا تفرّغٍ مُعلَن قيل عنه ذلك بالفعل؛ لا يُقال مرّتين
            // بصيغتين.
            if (empty($this->constraints->availability[$requirement->staffId])) {
                continue;
            }

            $this->conflicts[] = new TimetableConflict(
                'assignment_has_no_valid_slot',
                [
                    'teacher' => $this->constraints->teacherName($requirement->staffId),
                    'subject' => $this->constraints->subjectName($requirement->subjectId),
                    'section' => $this->constraints->sectionName($requirement->sectionId),
                    'minutes' => $requirement->lessonMinutes,
                ],
                $requirement->staffId,
                $requirement->sectionId,
                $requirement->subjectId,
            );
        }
    }

    /**
     * سعة الأستاذ: ما يحتاجه من دقائق مقابل ما يتّسع تفرّغه له.
     *
     * تُقاس بالدقائق لا بالحصص: أستاذٌ يدرّس شعبتين بطولي حصّة مختلفين
     * (صباحية ٦٠ ومسائية ٩٠) لا يُقاس بعدد الحصص.
     */
    private function checkTeacherCapacity(): void
    {
        $needed = [];

        foreach ($this->requirements as $requirement) {
            $needed[$requirement->staffId] = ($needed[$requirement->staffId] ?? 0)
                + $requirement->lessonMinutes;
        }

        foreach ($needed as $staffId => $requiredMinutes) {
            if (empty($this->constraints->availability[$staffId])) {
                continue; // قيل عنه أنه بلا تفرّغ.
            }

            $available = $this->constraints->teacherMinutes($staffId);

            if ($requiredMinutes <= $available) {
                continue;
            }

            $lessonMinutes = $this->lessonMinutesOf($staffId);

            $this->conflicts[] = new TimetableConflict(
                'teacher_capacity_exceeded',
                [
                    'teacher' => $this->constraints->teacherName($staffId),
                    'required_lessons' => (int) ceil($requiredMinutes / $lessonMinutes),
                    'available_lessons' => intdiv($available, $lessonMinutes),
                    'required_minutes' => $requiredMinutes,
                    'available_minutes' => $available,
                ],
                staffId: $staffId,
            );
        }
    }

    /**
     * سعة الشعبة: الحصص المطلوبة لها مقابل ما يتّسع دوامها له.
     *
     * والمحجوز يدويّاً يُخصَم: شعبةٌ ملأ المستخدم نصف جدولها بيده لم يبق لها
     * إلاّ النصف.
     */
    private function checkSectionCapacity(): void
    {
        $needed = [];

        foreach ($this->requirements as $requirement) {
            $needed[$requirement->sectionId] = ($needed[$requirement->sectionId] ?? 0) + 1;
        }

        foreach ($needed as $sectionId => $requiredLessons) {
            $capacity = $this->sectionCapacity($sectionId);

            if ($requiredLessons <= $capacity) {
                continue;
            }

            $this->conflicts[] = new TimetableConflict(
                'section_capacity_exceeded',
                [
                    'section' => $this->constraints->sectionName($sectionId),
                    'required_lessons' => $requiredLessons,
                    'available_lessons' => $capacity,
                ],
                sectionId: $sectionId,
            );
        }
    }

    /** عدد الحصص غير المتداخلة التي يتّسع لها دوام الشعبة في الأسبوع. */
    private function sectionCapacity(int $sectionId): int
    {
        $minutes = $this->constraints->lessonMinutesFor($sectionId);
        $capacity = 0;

        foreach ($this->constraints->openDays() as $weekday) {
            $window = $this->constraints->windowFor($sectionId, $weekday->value);

            if ($window === null || $window->isEmpty()) {
                continue;
            }

            $capacity += intdiv($window->minutes_(), $minutes);
        }

        $manual = count($this->constraints->manualBySection[$sectionId] ?? []);

        return max(0, $capacity - $manual);
    }

    private function lessonMinutesOf(int $staffId): int
    {
        foreach ($this->requirements as $requirement) {
            if ($requirement->staffId === $staffId) {
                return $requirement->lessonMinutes;
            }
        }

        return $this->constraints->defaultLessonMinutes;
    }

    // ----------------------------------------------------------------- stats

    /**
     * الأرقام التي تُعرَض للمدير قبل التوليد.
     *
     * تُحفظ في `timetable_runs.stats` فتبقى مقروءةً بعد إغلاق الشاشة.
     */
    private function summarise(): array
    {
        $sections = [];

        foreach ($this->constraints->sectionIds() as $sectionId) {
            $required = count(array_filter(
                $this->requirements,
                fn (LessonRequirement $r) => $r->sectionId === $sectionId,
            ));

            $sections[] = [
                'section_id' => $sectionId,
                'section' => $this->constraints->sectionName($sectionId),
                'lesson_minutes' => $this->constraints->lessonMinutesFor($sectionId),
                'required_lessons' => $required,
                'available_lessons' => $this->sectionCapacity($sectionId),
            ];
        }

        $teachers = [];
        $staffIds = $this->constraints->assignments->pluck('staff_id')->unique()->sort()->values();

        foreach ($staffIds as $staffId) {
            $minutes = 0;

            foreach ($this->requirements as $requirement) {
                if ($requirement->staffId === $staffId) {
                    $minutes += $requirement->lessonMinutes;
                }
            }

            $teachers[] = [
                'staff_id' => $staffId,
                'teacher' => $this->constraints->teacherName($staffId),
                'required_minutes' => $minutes,
                'available_minutes' => $this->constraints->teacherMinutes($staffId),
                'has_availability' => ! empty($this->constraints->availability[$staffId]),
            ];
        }

        return [
            'lesson_minutes' => $this->constraints->defaultLessonMinutes,
            'granularity_minutes' => TimetableConstraints::GRANULARITY,
            'open_days' => array_map(
                fn ($day) => ['day' => $day->value, 'label' => $day->label()],
                $this->constraints->openDays(),
            ),
            'assignments' => $this->constraints->assignments->count(),
            'required_lessons' => count($this->requirements),
            'manual_lessons' => array_sum(array_map(
                'count',
                $this->constraints->manualBySection,
            )),
            'sections' => $sections,
            'teachers' => $teachers,
        ];
    }

    /** الإسنادات كما تُعرَض في خطوة المراجعة: أستاذ ← مادة ← شعبة ← عدد. */
    public function assignmentRows(): array
    {
        return $this->constraints->assignments
            ->map(fn (TeacherAssignment $a) => [
                'id' => $a->id,
                'staff_id' => $a->staff_id,
                'teacher' => $this->constraints->teacherName($a->staff_id),
                'subject_id' => $a->subject_id,
                'subject' => $this->constraints->subjectName($a->subject_id),
                'section_id' => $a->section_id,
                'section' => $this->constraints->sectionName($a->section_id),
                'lessons_per_week' => $a->lessonsPerWeek(),
                'explicit' => $a->lessons_per_week !== null,
            ])
            ->values()
            ->all();
    }
}
