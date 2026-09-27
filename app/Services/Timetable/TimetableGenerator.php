<?php

namespace App\Services\Timetable;

use App\Enums\TimetableRunStatus;
use App\Models\ScheduleSlot;
use App\Models\School;
use App\Models\TimetableRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * توليد الجدول بإرضاء قيود، لا بمحاولاتٍ عشوائيّة.
 *
 * **لماذا التراجُع (backtracking) لا الوضع الطمّاع.** الطمّاع يضع كل حصّة في
 * أوّل موضعٍ يصحّ لها، فيستهلك الموضع الوحيد الذي كان يصلح لحصّةٍ لاحقة أضيق
 * حالاً، ثمّ يفشل ويقول «مستحيل» وهو ممكن. والتراجُع يسحب آخر قرار ويجرّب
 * غيره، فلا يقول «مستحيل» إلاّ بعد أن يستنفد الاحتمالات فعلاً.
 *
 * **ثلاث حِيَل تجعله ينتهي في زمنٍ مقبول:**
 *
 *  1. *الأضيق أوّلاً* (MRV): تُختار في كل خطوة الحصّةُ التي بقي لها أقلّ عدد
 *     من المواضع. فالفشل يظهر بعد قرارين لا بعد مئتين.
 *  2. *الفشل المبكر*: إن بقي مطلبٌ بلا موضعٍ واحد، يُتراجَع فوراً بلا أن
 *     يُجرَّب شيء.
 *  3. *ميزانيّة خطوات*: الاستضافة مشتركة ولا عامل طوابير فيها، فالتوليد يجري
 *     داخل الطلب. لا يُسمح له أن يدور إلى ما لا نهاية: عند نفاد الميزانيّة
 *     يُعلَن العجز صريحاً — ولا يُكتب جدولٌ ناقص.
 *
 * **وحتميّ**: لا عشوائيّة في أي خطوة. الترتيب مفروض في كل مقارنة، فنفس
 * المُدخل يعطي نفس الجدول في كل تشغيل.
 *
 * **ولا يُكتب شيء إلاّ عند النجاح الكامل.** جدولٌ فيه ٣٩ حصّة من ٤٠ ليس جدولاً
 * أنقص قليلاً، بل جدولٌ يكتشف صفٌّ غيابَ مادّته بعد أسبوع.
 */
class TimetableGenerator
{
    /** حدّ أعلى لعدد قرارات الوضع — يحرس من طلبٍ لا ينتهي. */
    public const STEP_BUDGET = 150_000;

    /** @var array<string, array{day:int,range:TimeRange,period:int}> */
    private array $placements = [];

    /** @var array<int, array<int, list<TimeRange>>> [staff][day] */
    private array $teacherBusy = [];

    /** @var array<int, array<int, list<TimeRange>>> [section][day] */
    private array $sectionBusy = [];

    /** @var array<int, array<int, list<int>>> [section][day] => أرقام الحصص */
    private array $sectionPeriods = [];

    /** @var array<string, int> [section:subject:day] => عدد حصص المادة ذلك اليوم */
    private array $subjectPerDay = [];

    private int $steps = 0;

    private bool $exhausted = false;

    public function __construct(
        private readonly TimetableConstraints $constraints,
        private readonly TimetableAnalyzer $analyzer,
    ) {}

    /**
     * يولّد الجدول ويحفظه، أو يُعيد تقرير تعارضٍ ولا يحفظ شيئاً.
     *
     * المحاولة تُسجَّل في الحالين: النجاح ليُعرَف ما حصصه، والفشل ليبقى سببه
     * مقروءاً بعد إغلاق الشاشة.
     */
    public function run(School $school, ?User $requestedBy = null): TimetableRun
    {
        $term = $this->constraints->term;

        // التحليل أوّلاً: لا يُنفَق وقت تراجُعٍ على قيدٍ مستحيل من أوّله.
        if (! $this->analyzer->isFeasible()) {
            return $this->record($school, $requestedBy, TimetableRunStatus::Infeasible, [
                'conflicts' => $this->analyzer->conflictsArray(),
                'placed' => 0,
            ]);
        }

        $requirements = $this->analyzer->requirements();

        if ($requirements === []) {
            return $this->record($school, $requestedBy, TimetableRunStatus::Infeasible, [
                'conflicts' => [
                    (new TimetableConflict('nothing_to_schedule'))->toArray(),
                ],
                'placed' => 0,
            ]);
        }

        $this->reset();
        $solved = $this->solve($requirements);

        if (! $solved) {
            return $this->record($school, $requestedBy, TimetableRunStatus::Infeasible, [
                'conflicts' => $this->failureReport($requirements),
                'placed' => count($this->placements),
            ]);
        }

        $run = $this->record($school, $requestedBy, TimetableRunStatus::Generated, [
            'conflicts' => [],
            'placed' => count($this->placements),
        ]);

        $this->persist($run, $requirements);

        // التحقّق **بعد** الكتابة، من قاعدة البيانات لا من الذاكرة: خطأٌ في
        // المولّد لا يجوز أن يمرّ لأن المولّد نفسه هو من صدّق على نفسه.
        $validation = TimetableValidator::validateRun($run);

        if ($validation !== []) {
            // لا يُترك جدولٌ فيه تعارض: تُمحى حصص المحاولة وتُعلَن مستحيلة.
            DB::transaction(function () use ($run, $validation) {
                $run->slots()->delete();
                $run->update([
                    'status' => TimetableRunStatus::Infeasible,
                    'conflicts' => $validation,
                    'lessons_placed' => 0,
                ]);
            });

            return $run->refresh();
        }

        return $run;
    }

    private function reset(): void
    {
        $this->placements = [];
        $this->teacherBusy = [];
        $this->sectionBusy = [];
        $this->sectionPeriods = [];
        $this->subjectPerDay = [];
        $this->steps = 0;
        $this->exhausted = false;
    }

    // ------------------------------------------------------------- the solver

    /**
     * @param  list<LessonRequirement>  $requirements
     */
    private function solve(array $requirements): bool
    {
        if (count($this->placements) === count($requirements)) {
            return true;
        }

        if ($this->steps >= self::STEP_BUDGET) {
            $this->exhausted = true;

            return false;
        }

        $next = $this->mostConstrained($requirements);

        // مطلبٌ بقي بلا موضع: لا معنى لتجربة شيء، يُتراجَع فوراً.
        if ($next === null) {
            return false;
        }

        [$requirement, $options] = $next;

        foreach ($options as $candidate) {
            $this->steps++;

            if ($this->steps > self::STEP_BUDGET) {
                $this->exhausted = true;

                return false;
            }

            $this->place($requirement, $candidate);

            if ($this->solve($requirements)) {
                return true;
            }

            $this->unplace($requirement, $candidate);
        }

        return false;
    }

    /**
     * المطلب الذي بقي له أقلّ المواضع، مع مواضعه مرتّبةً.
     *
     * يُعيد `null` إن كان لأحد المطالب صفرُ مواضع — إشارةَ تراجُعٍ فوريّ.
     *
     * @param  list<LessonRequirement>  $requirements
     * @return array{0:LessonRequirement,1:list<array{day:int,range:TimeRange,period:int}>}|null
     */
    private function mostConstrained(array $requirements): ?array
    {
        $best = null;
        $bestOptions = [];
        $bestCount = PHP_INT_MAX;

        foreach ($requirements as $requirement) {
            if (isset($this->placements[$requirement->key()])) {
                continue;
            }

            $options = $this->viableFor($requirement);
            $count = count($options);

            if ($count === 0) {
                return null;
            }

            // التعادل يُفصَل بالمفتاح لا بترتيب الوصول: الحتميّة تتطلّب
            // ترتيباً كاملاً، وترتيبُ المصفوفة وحده ليس كذلك بعد التراجُع.
            if ($count < $bestCount
                || ($count === $bestCount && $best !== null && $requirement->key() < $best->key())
            ) {
                $best = $requirement;
                $bestOptions = $options;
                $bestCount = $count;
            }
        }

        return $best === null ? null : [$best, $bestOptions];
    }

    /**
     * مواضع هذا المطلب التي ما زالت صالحة الآن، مرتّبةً بالأفضليّة.
     *
     * @return list<array{day:int,range:TimeRange,period:int}>
     */
    private function viableFor(LessonRequirement $requirement): array
    {
        $options = [];

        foreach ($requirement->candidates as $candidate) {
            if ($this->conflicts($requirement, $candidate)) {
                continue;
            }

            $options[] = $candidate;
        }

        // **التوزيع على الأيّام أوّلاً.** أربع حصص رياضيات في يومٍ واحد جدولٌ
        // صحيحٌ حسابيّاً وخطأٌ تربويّاً. فيُفضَّل يومٌ لا مادةَ فيه للشعبة، ثمّ
        // الأسبق يوماً، ثمّ الأسبق وقتاً — ولا موضعَ يُستبعَد، إنما يُؤخَّر:
        // إن لم يبقَ إلاّ التكرار في اليوم نفسه فهو أفضل من جدولٍ ناقص.
        usort($options, function (array $a, array $b) use ($requirement) {
            $spreadA = $this->sameDayCount($requirement, $a['day']);
            $spreadB = $this->sameDayCount($requirement, $b['day']);

            return [$spreadA, $a['day'], $a['range']->start]
                <=> [$spreadB, $b['day'], $b['range']->start];
        });

        return $options;
    }

    private function sameDayCount(LessonRequirement $requirement, int $day): int
    {
        return $this->subjectPerDay[$this->dayKey($requirement, $day)] ?? 0;
    }

    private function dayKey(LessonRequirement $requirement, int $day): string
    {
        return "{$requirement->sectionId}:{$requirement->subjectId}:{$day}";
    }

    /** القيود الصلبة: أستاذٌ في مكانين، أو شعبةٌ في درسين، أو رقمُ حصّةٍ محجوز. */
    private function conflicts(LessonRequirement $requirement, array $candidate): bool
    {
        $day = $candidate['day'];
        $lesson = $candidate['range'];

        foreach ($this->teacherBusy[$requirement->staffId][$day] ?? [] as $busy) {
            if ($busy->overlaps($lesson)) {
                return true;
            }
        }

        foreach ($this->sectionBusy[$requirement->sectionId][$day] ?? [] as $busy) {
            if ($busy->overlaps($lesson)) {
                return true;
            }
        }

        $taken = $this->sectionPeriods[$requirement->sectionId][$day] ?? [];

        return in_array($candidate['period'], $taken, true);
    }

    private function place(LessonRequirement $requirement, array $candidate): void
    {
        $day = $candidate['day'];

        $this->placements[$requirement->key()] = $candidate;
        $this->teacherBusy[$requirement->staffId][$day][] = $candidate['range'];
        $this->sectionBusy[$requirement->sectionId][$day][] = $candidate['range'];
        $this->sectionPeriods[$requirement->sectionId][$day][] = $candidate['period'];

        $key = $this->dayKey($requirement, $day);
        $this->subjectPerDay[$key] = ($this->subjectPerDay[$key] ?? 0) + 1;
    }

    private function unplace(LessonRequirement $requirement, array $candidate): void
    {
        $day = $candidate['day'];

        unset($this->placements[$requirement->key()]);

        $this->teacherBusy[$requirement->staffId][$day] = $this->without(
            $this->teacherBusy[$requirement->staffId][$day] ?? [],
            $candidate['range'],
        );
        $this->sectionBusy[$requirement->sectionId][$day] = $this->without(
            $this->sectionBusy[$requirement->sectionId][$day] ?? [],
            $candidate['range'],
        );

        $periods = $this->sectionPeriods[$requirement->sectionId][$day] ?? [];
        $at = array_search($candidate['period'], $periods, true);

        if ($at !== false) {
            unset($periods[$at]);
            $this->sectionPeriods[$requirement->sectionId][$day] = array_values($periods);
        }

        $key = $this->dayKey($requirement, $day);
        $this->subjectPerDay[$key] = max(0, ($this->subjectPerDay[$key] ?? 1) - 1);
    }

    /**
     * يحذف **نسخةً واحدة** من المدى لا كل مثيلاته.
     *
     * حصّتان لأستاذين مختلفين في الوقت نفسه لشعبتين مختلفتين تُنتجان مديين
     * متساويين في القيمة؛ حذفهما معاً عند التراجُع يُفقد قيداً قائماً فيُنتج
     * جدولاً متعارضاً.
     *
     * @param  list<TimeRange>  $ranges
     * @return list<TimeRange>
     */
    private function without(array $ranges, TimeRange $range): array
    {
        foreach ($ranges as $index => $existing) {
            if ($existing->start === $range->start && $existing->end === $range->end) {
                unset($ranges[$index]);

                return array_values($ranges);
            }
        }

        return array_values($ranges);
    }

    // ---------------------------------------------------------------- results

    /**
     * لماذا فشل التراجُع بعد أن قال التحليل «ممكن».
     *
     * التحليل يقيس كل قيدٍ وحده؛ الفشل هنا معناه أنّ القيود تتزاحم **معاً**.
     * فيُسمّى من بقي بلا موضع، لا يُقال «تعارض» فقط.
     *
     * @param  list<LessonRequirement>  $requirements
     */
    private function failureReport(array $requirements): array
    {
        if ($this->exhausted) {
            return [(new TimetableConflict('search_budget_exhausted', [
                'placed' => count($this->placements),
                'required' => count($requirements),
            ]))->toArray()];
        }

        $unplaced = [];

        foreach ($requirements as $requirement) {
            if (isset($this->placements[$requirement->key()])) {
                continue;
            }

            $key = $requirement->assignmentKey();
            $unplaced[$key] = ($unplaced[$key] ?? 0) + 1;
        }

        $conflicts = [];

        foreach ($requirements as $requirement) {
            $key = $requirement->assignmentKey();

            if (! isset($unplaced[$key])) {
                continue;
            }

            $missing = $unplaced[$key];
            unset($unplaced[$key]);

            $conflicts[] = (new TimetableConflict(
                'mutually_exclusive_assignment',
                [
                    'teacher' => $this->constraints->teacherName($requirement->staffId),
                    'subject' => $this->constraints->subjectName($requirement->subjectId),
                    'section' => $this->constraints->sectionName($requirement->sectionId),
                    'missing' => $missing,
                    'required' => $requirement->totalForAssignment,
                ],
                $requirement->staffId,
                $requirement->sectionId,
                $requirement->subjectId,
            ))->toArray();
        }

        return $conflicts;
    }

    private function record(
        School $school,
        ?User $requestedBy,
        TimetableRunStatus $status,
        array $result,
    ): TimetableRun {
        return TimetableRun::create([
            'school_id' => $school->id,
            'term_id' => $this->constraints->term->id,
            'status' => $status,
            'requested_by' => $requestedBy?->id,
            'lesson_minutes' => $this->constraints->defaultLessonMinutes,
            'stats' => $this->analyzer->stats(),
            'conflicts' => $result['conflicts'],
            'lessons_placed' => $result['placed'],
            'lessons_required' => $this->analyzer->lessonsRequired(),
        ]);
    }

    /**
     * يكتب حصص المحاولة، ويمحو المولَّد السابق **وحده**.
     *
     * الحصص اليدويّة لا تُمَسّ: `timetable_run_id` الفارغ علامتها، وهي جدولٌ
     * قائم في الإنتاج بناه المستخدم بيده.
     *
     * @param  list<LessonRequirement>  $requirements
     */
    private function persist(TimetableRun $run, array $requirements): void
    {
        $byKey = [];

        foreach ($requirements as $requirement) {
            $byKey[$requirement->key()] = $requirement;
        }

        DB::transaction(function () use ($run, $byKey) {
            ScheduleSlot::query()
                ->where('term_id', $run->term_id)
                ->generated()
                ->whereHas(
                    'timetableRun',
                    fn ($q) => $q->where('school_id', $run->school_id),
                )
                ->delete();

            $rows = [];
            $now = now();

            foreach ($this->placements as $key => $candidate) {
                $requirement = $byKey[$key];

                $rows[] = [
                    'section_id' => $requirement->sectionId,
                    'term_id' => $run->term_id,
                    'subject_id' => $requirement->subjectId,
                    'staff_id' => $requirement->staffId,
                    'timetable_run_id' => $run->id,
                    'day_of_week' => $candidate['day'],
                    'starts_at' => TimeRange::format($candidate['range']->start),
                    'ends_at' => TimeRange::format($candidate['range']->end),
                    'period_number' => $candidate['period'],
                    'room' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($rows, 200) as $chunk) {
                ScheduleSlot::query()->insert($chunk);
            }
        });
    }
}
