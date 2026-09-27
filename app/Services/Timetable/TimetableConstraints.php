<?php

namespace App\Services\Timetable;

use App\Enums\Weekday;
use App\Models\ScheduleSlot;
use App\Models\School;
use App\Models\SchoolDayHours;
use App\Models\Section;
use App\Models\SectionDayHours;
use App\Models\TeacherAssignment;
use App\Models\TeacherAvailability;
use App\Models\Term;
use Illuminate\Support\Collection;

/**
 * القيود كما هي في قاعدة البيانات، مقروءةً مرّة واحدة.
 *
 * يُقرأ كلّ شيء هنا ثم يعمل المحلّل والمولّد في الذاكرة: الاستضافة مشتركة
 * ولا عامل طوابير فيها، فالتوليد يجري داخل الطلب. استعلامٌ داخل حلقة
 * التراجُع كان يعني آلاف الاستعلامات في الطلب الواحد.
 *
 * ثلاثة مفاهيم مفصولة قصداً:
 *
 *  - **دوام المعهد** (`school_day_hours`): الحدّ الخارجي. يومٌ بلا صفٍّ مغلق.
 *  - **دوام الشعبة** (`section_day_hours`): يضيّق دوام المعهد لشعبةٍ بعينها،
 *    وهو جدول قائم يخدم الشعبة الصباحية والمسائية في المبنى نفسه. يومٌ بلا
 *    صفٍّ عطلةٌ لتلك الشعبة.
 *  - **تفرّغ الأستاذ** (`teacher_availability`): متى يستطيع هو.
 *
 * والنافذة الفعليّة تقاطُع الثلاثة. ثمّ تصحّ الحصّة إن دخلت **بكاملها** فيها:
 * تفرّغٌ من ١١:٣٠ إلى ١٣:٠٠ وحصّةٌ من ستّين دقيقة يعني ابتداءين ممكنين
 * (١١:٣٠ و١٢:٠٠)، لا ثلاث حصص من نصف ساعة.
 */
class TimetableConstraints
{
    /** دقّة اقتراح مواعيد البدء — نصف ساعة، كشبكة التأشير. ليست طول الحصّة. */
    public const GRANULARITY = TeacherAvailability::SLOT_MINUTES;

    /** @var array<int, TimeRange> دوام المعهد، بمفتاح اليوم. */
    public array $schoolHours = [];

    /** @var array<int, array<int, TimeRange>> دوام الشعبة: [section][day]. */
    public array $sectionHours = [];

    /** @var array<int, int> طول الحصّة لكل شعبة، بالدقائق. */
    public array $sectionLessonMinutes = [];

    /** @var array<int, array<int, list<TimeRange>>> تفرّغ الأستاذ: [staff][day]. */
    public array $availability = [];

    /** @var Collection<int, TeacherAssignment> */
    public Collection $assignments;

    /** @var array<int, list<TimeRange>> حصص يدويّة قائمة، بمفتاح الشعبة. */
    public array $manualBySection = [];

    /** @var array<int, list<TimeRange>> حصص يدويّة قائمة، بمفتاح الأستاذ. */
    public array $manualByStaff = [];

    /** @var array<int, array<int, list<int>>> أرقام الحصص المحجوزة: [section][day]. */
    public array $takenPeriods = [];

    /** @var array<int, string> أسماء الأساتذة، للتقرير. */
    public array $teacherNames = [];

    /** @var array<int, string> أسماء الشعب مع صفوفها، للتقرير. */
    public array $sectionNames = [];

    /** @var array<int, string> أسماء المواد، للتقرير. */
    public array $subjectNames = [];

    public int $defaultLessonMinutes = 60;

    private function __construct(
        public School $school,
        public Term $term,
    ) {
        $this->assignments = collect();
    }

    public static function load(School $school, Term $term): self
    {
        $constraints = new self($school, $term);
        $constraints->defaultLessonMinutes = $school->lessonMinutes();

        $constraints->loadSchoolHours();
        $constraints->loadAssignments();
        $constraints->loadSectionHours();
        $constraints->loadAvailability();
        $constraints->loadManualSlots();

        return $constraints;
    }

    private function loadSchoolHours(): void
    {
        foreach (SchoolDayHours::query()->ofSchool($this->school->id)->get() as $row) {
            $this->schoolHours[$row->day_of_week->value] = TimeRange::of(
                (string) $row->starts_at,
                (string) $row->ends_at,
            );
        }
    }

    /**
     * الإسنادات التي يجدولها المولّد.
     *
     * تُقيَّد بفصل الجدول: مادةُ الإسناد يجب أن تكون في هذا الفصل، وشعبتُه في
     * سنة هذا الفصل. بلا هذا القيد يجدول المولّد مادةَ فصلٍ ماضٍ.
     */
    private function loadAssignments(): void
    {
        $this->assignments = TeacherAssignment::query()
            ->ofSchool($this->school->id)
            ->whereHas('subject', fn ($q) => $q->where('term_id', $this->term->id))
            ->whereHas(
                'section',
                fn ($q) => $q->where('academic_year_id', $this->term->academic_year_id),
            )
            ->with(['teacher', 'subject', 'section.grade'])
            ->get()
            // ترتيب صريح: التوليد يجب أن يعطي النتيجة نفسها لنفس المُدخل.
            ->sortBy([
                fn (TeacherAssignment $a) => $a->section_id,
                fn (TeacherAssignment $a) => $a->subject_id,
                fn (TeacherAssignment $a) => $a->staff_id,
            ])
            ->values();

        foreach ($this->assignments as $assignment) {
            if ($assignment->teacher) {
                $this->teacherNames[$assignment->staff_id] = $assignment->teacher->full_name;
            }

            if ($assignment->subject) {
                $this->subjectNames[$assignment->subject_id] = $assignment->subject->name;
            }

            $section = $assignment->section;

            if ($section) {
                $this->sectionNames[$section->id] = trim(
                    ($section->grade?->name ?? '').' - '.$section->name,
                    ' -',
                );
            }
        }
    }

    private function loadSectionHours(): void
    {
        $sectionIds = $this->assignments->pluck('section_id')->unique()->values();

        if ($sectionIds->isEmpty()) {
            return;
        }

        $rows = SectionDayHours::query()->whereIn('section_id', $sectionIds)->get();

        foreach ($rows as $row) {
            $sectionId = $row->section_id;
            $this->sectionHours[$sectionId][$row->day_of_week->value] = TimeRange::of(
                (string) $row->starts_at,
                (string) $row->ends_at,
            );

            // طول الحصّة: ما على دوام الشعبة يعلو على افتراض المعهد. الشعبة
            // الصباحية والمسائية قد تختلفان، وهذا العمود قائم يخدم ذلك.
            $this->sectionLessonMinutes[$sectionId] = max(
                5,
                (int) ($row->period_minutes ?: $this->defaultLessonMinutes),
            );
        }
    }

    private function loadAvailability(): void
    {
        $staffIds = $this->assignments->pluck('staff_id')->unique()->values();

        if ($staffIds->isEmpty()) {
            return;
        }

        $rows = TeacherAvailability::query()
            ->whereIn('staff_id', $staffIds)
            ->orderBy('starts_at')
            ->get();

        foreach ($rows as $row) {
            $this->availability[$row->staff_id][$row->day_of_week->value][] = TimeRange::of(
                (string) $row->starts_at,
                (string) $row->ends_at,
            );
        }
    }

    /**
     * الحصص التي بناها المستخدم بيده تبقى حيث هي.
     *
     * جدولٌ قائم في الإنتاج بُني بالشاشة الحالية؛ حذفه لأنّ أحداً ضغط «ولّد»
     * خسارةٌ لا تُستعاد. فالمولّد يعدّها قيداً ثابتاً: وقتها محجوز على الشعبة
     * وعلى الأستاذ، ويجدول حوله.
     */
    private function loadManualSlots(): void
    {
        $slots = ScheduleSlot::query()
            ->where('term_id', $this->term->id)
            ->manual()
            ->get();

        foreach ($slots as $slot) {
            $range = TimeRange::of((string) $slot->starts_at, (string) $slot->ends_at);
            $day = $slot->day_of_week->value;

            $this->manualBySection[$slot->section_id][] = [$day, $range];

            if ($slot->staff_id) {
                $this->manualByStaff[$slot->staff_id][] = [$day, $range];
            }

            $this->takenPeriods[$slot->section_id][$day][] = $slot->period_number;
        }
    }

    /** طول الحصّة لهذه الشعبة: ما على دوامها، وإلاّ افتراض المعهد. */
    public function lessonMinutesFor(int $sectionId): int
    {
        return $this->sectionLessonMinutes[$sectionId] ?? $this->defaultLessonMinutes;
    }

    /** أيّام الأسبوع الدراسي التي يفتح فيها المعهد. */
    public function openDays(): array
    {
        return array_values(array_filter(
            Weekday::schoolWeek(),
            fn (Weekday $day) => isset($this->schoolHours[$day->value])
                && ! $this->schoolHours[$day->value]->isEmpty(),
        ));
    }

    /**
     * النافذة الفعليّة لشعبةٍ في يوم: دوام المعهد ∩ دوام الشعبة.
     *
     * شعبةٌ بلا دوامٍ مضبوط تأخذ دوام المعهد كما هو — فمعهدٌ لم يضبط دوام
     * شعبةٍ بعد يبقى قابلاً للجدولة بدل أن يُقال له «لا نافذة».
     */
    public function windowFor(int $sectionId, int $day): ?TimeRange
    {
        $school = $this->schoolHours[$day] ?? null;

        if ($school === null || $school->isEmpty()) {
            return null;
        }

        $hasSectionWeek = ! empty($this->sectionHours[$sectionId]);

        if (! $hasSectionWeek) {
            return $school;
        }

        $section = $this->sectionHours[$sectionId][$day] ?? null;

        // للشعبة دوامٌ مضبوط لأيّامٍ أخرى وليس لهذا اليوم: هذا اليوم عطلتها.
        if ($section === null) {
            return null;
        }

        return $school->intersect($section);
    }

    /**
     * مواعيد البدء الممكنة لحصّة في نافذة، بخطوة نصف ساعة.
     *
     * الخطوة من بداية النافذة لا من منتصف الليل: نافذةٌ تبدأ ١١:١٥ تقترح
     * ١١:١٥ و١١:٤٥، لا ١١:٣٠ التي ليست على حدّها.
     *
     * @return list<int>
     */
    public function startsWithin(TimeRange $window, int $lessonMinutes): array
    {
        $starts = [];

        for (
            $start = $window->start;
            $start + $lessonMinutes <= $window->end;
            $start += self::GRANULARITY
        ) {
            $starts[] = $start;
        }

        return $starts;
    }

    /** هل الأستاذ متفرّغ لهذا المدى **بكامله** في هذا اليوم؟ */
    public function teacherIsFree(int $staffId, int $day, TimeRange $lesson): bool
    {
        foreach ($this->availability[$staffId][$day] ?? [] as $range) {
            if ($range->contains($lesson)) {
                return true;
            }
        }

        return false;
    }

    /** هل يتعارض هذا المدى مع حصّة يدويّة قائمة للشعبة؟ */
    public function sectionBusyManually(int $sectionId, int $day, TimeRange $lesson): bool
    {
        return $this->clashes($this->manualBySection[$sectionId] ?? [], $day, $lesson);
    }

    /** هل يتعارض هذا المدى مع حصّة يدويّة قائمة للأستاذ؟ */
    public function teacherBusyManually(int $staffId, int $day, TimeRange $lesson): bool
    {
        return $this->clashes($this->manualByStaff[$staffId] ?? [], $day, $lesson);
    }

    /** @param  list<array{0:int,1:TimeRange}>  $ranges */
    private function clashes(array $ranges, int $day, TimeRange $lesson): bool
    {
        foreach ($ranges as [$rangeDay, $range]) {
            if ($rangeDay === $day && $range->overlaps($lesson)) {
                return true;
            }
        }

        return false;
    }

    /** دقائق تفرّغ الأستاذ في الأسبوع — سعته العليا. */
    public function teacherMinutes(int $staffId): int
    {
        $total = 0;

        foreach ($this->availability[$staffId] ?? [] as $day => $ranges) {
            if (! isset($this->schoolHours[$day])) {
                // تفرّغٌ في يومٍ مغلق لا يُحسَب سعةً: يَعِد بما لا يقع.
                continue;
            }

            foreach ($ranges as $range) {
                $inside = $range->intersect($this->schoolHours[$day]);
                $total += $inside?->minutes_() ?? 0;
            }
        }

        return $total;
    }

    public function teacherName(int $staffId): string
    {
        return $this->teacherNames[$staffId] ?? '#'.$staffId;
    }

    public function sectionName(int $sectionId): string
    {
        return $this->sectionNames[$sectionId] ?? '#'.$sectionId;
    }

    public function subjectName(int $subjectId): string
    {
        return $this->subjectNames[$subjectId] ?? '#'.$subjectId;
    }

    /** أرقام الحصص المحجوزة لشعبةٍ في يوم — لا يُعاد استعمالها. */
    public function periodTaken(int $sectionId, int $day, int $period): bool
    {
        return in_array($period, $this->takenPeriods[$sectionId][$day] ?? [], true);
    }

    /** الشعب التي عليها إسنادات — وهي وحدها ما يُجدوَل. */
    public function sectionIds(): array
    {
        return $this->assignments->pluck('section_id')->unique()->values()->all();
    }

    public function sections(): Collection
    {
        return $this->assignments->pluck('section')->filter()->unique('id')->values();
    }

    public function sectionModel(int $sectionId): ?Section
    {
        return $this->assignments->firstWhere('section_id', $sectionId)?->section;
    }
}
