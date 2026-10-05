<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\AssessmentType;
use App\Models\GradeScore;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * التسميع — نوع تقييم، لا نظام درجات ثانٍ.
 *
 * **لماذا لا جدولَ جديداً.** المطلوب أن يُسمَّع بعض طلاب الشعبة لا كلّهم، وألّا
 * تنزل علامةُ من لم يُسمَّع. وهذا **هو** سلوك `grades_scores` أصلاً: لا صفّ =
 * لا درجة. و`SubjectGrade` يُخرج من الحساب كلَّ نوعٍ لا علامة فيه ويعيد توزيع
 * وزنه، فالطالب الذي لم يُسمَّع لا يُعاقَب على تسميعٍ لم يشترك فيه. فجدولٌ
 * جديد للتسميع كان يعني نسخةً ثانية من الحساب تفترق عن الأولى عند أول تعديل.
 *
 * **جلسة التسميع** = تقييمٌ واحد من نوع «تسميع»، لشعبةٍ في مادةٍ في تاريخ.
 * واسمه يُبنى هنا لا في العميل، لسببٍ عمليّ: الفهرس الفريد
 * `unique(subject_id, name)` قائم في قاعدة البيانات، فاسمٌ مبنيّ من الشعبة
 * والتاريخ يجعل ذلك الفهرسَ نفسَه هو ما يمنع جلستين مكرّرتين لنفس الشعبة في
 * نفس اليوم — بلا إضعاف ضمانٍ قائم ولا عمودٍ جديد.
 *
 * **ولا يُدرَج صفٌّ لطالبٍ لم يُختَر.** لا صفراً، ولا `null`، ولا صفّاً فارغاً:
 * صفٌّ بعلامة `null` يظهر في شبكة الإدخال خليّةً فارغة تنتظر علامة، فيبدو
 * التسميع ناقصاً وهو تامّ.
 */
class TasmiSession
{
    /** اسم نوع التقييم في كل مدرسة. */
    public const TYPE_NAME = 'تسميع';

    /** العلامة القصوى لجلسة تسميعٍ جديدة: من عشرة. */
    public const DEFAULT_MAX = 10;

    /** وزنه من العلامة النهائية `null`: يتقاسم ما تبقّى، فلا يقلب أوزاناً قائمة. */
    public static function type(int $schoolId): AssessmentType
    {
        return AssessmentType::firstOrCreate(
            ['school_id' => $schoolId, 'name' => self::TYPE_NAME],
            [
                'is_exam' => false,
                'is_default' => false,
                'weight_percent' => null,
                'sort_order' => (int) AssessmentType::query()
                    ->where('school_id', $schoolId)
                    ->max('sort_order') + 1,
            ],
        );
    }

    /** هل هذا التقييم جلسةَ تسميع؟ */
    public static function isTasmi(Assessment $assessment): bool
    {
        return $assessment->type?->name === self::TYPE_NAME;
    }

    /**
     * يفتح جلسة التسميع لهذه الشعبة في هذا التاريخ، أو يُعيد القائمة.
     *
     * `firstOrCreate` مقصود: «حفظ» ثانياً في اليوم نفسه تعديلٌ للجلسة لا
     * جلسةٌ ثانية — وهو ما يتوقّعه الأستاذ الذي أضاف طالباً نسيه.
     */
    public static function open(
        Subject $subject,
        Section $section,
        Carbon $heldOn,
        User $teacher,
        ?float $maxScore = null,
    ): Assessment {
        $type = self::type($subject->grade->school_id);

        $assessment = Assessment::firstOrCreate(
            [
                'subject_id' => $subject->id,
                'name' => self::nameFor($section, $heldOn),
            ],
            [
                'section_id' => $section->id,
                'assessment_type_id' => $type->id,
                'held_on' => $heldOn->toDateString(),
                'created_by' => $teacher->id,
                'max_score' => $maxScore ?? self::DEFAULT_MAX,
                'weight_percent' => 0,
            ],
        );

        // جلسةٌ قائمة أُنشئت قبل أن يُضبط شيء: تُستكمل بلا أن تُنشأ ثانية.
        if ($assessment->section_id === null) {
            $assessment->forceFill(['section_id' => $section->id])->save();
        }

        return $assessment;
    }

    /**
     * الاسم الذي يصير مفتاحاً طبيعيّاً: «تسميع — التاسع أ — 2026-09-27».
     *
     * ليس تجميلاً: `unique(subject_id, name)` القائم يمنع به تكرار الجلسة.
     */
    public static function nameFor(Section $section, Carbon $heldOn): string
    {
        $label = trim(
            ($section->grade?->name ?? '').' '.$section->name,
        );

        return self::TYPE_NAME.' — '.$label.' — '.$heldOn->toDateString();
    }

    /**
     * يكتب درجات المشاركين، ويحذف صفَّ من أُخرج من الجلسة.
     *
     * الحذف مقصود ومساوٍ في الأهمّية للكتابة: أستاذٌ أشّر طالباً بالخطأ ثمّ
     * أزاله يجب أن يزول صفُّه، لا أن يبقى بعلامةٍ صفر أو `null` — فيقرأه
     * `SubjectGrade` تسميعاً حضره الطالب وأخذ فيه صفراً.
     *
     * @param  array<int, array{student_id:int, score:float|int|null}>  $entries
     * @return array{saved:int, removed:int, changed:list<int>}
     */
    public static function record(Assessment $assessment, array $entries, User $enteredBy): array
    {
        $wanted = [];

        foreach ($entries as $entry) {
            $score = $entry['score'] ?? null;

            // طالبٌ بلا علامة ليس مشاركاً: لا يُكتب له صفّ ينتظر رقماً.
            if ($score === null || $score === '') {
                continue;
            }

            $wanted[(int) $entry['student_id']] = (float) $score;
        }

        $changed = [];
        $removed = 0;

        DB::transaction(function () use ($assessment, $wanted, $enteredBy, &$changed, &$removed) {
            $existing = $assessment->scores()->get()->keyBy('student_id');

            foreach ($wanted as $studentId => $score) {
                $before = $existing->get($studentId);

                // الإشعار لا يُرسَل إلاّ عن علامةٍ **تغيّرت**: حفظٌ ثانٍ بلا
                // تعديل لا يُعيد إشعاراً على الأب.
                $isNew = $before === null;
                $wasDifferent = ! $isNew && (float) $before->score !== $score;

                $assessment->scores()->updateOrCreate(
                    ['student_id' => $studentId],
                    [
                        'score' => $score,
                        'entered_at' => now(),
                        'entered_by' => $enteredBy->id,
                    ],
                );

                if ($isNew || $wasDifferent) {
                    $changed[] = $studentId;
                }
            }

            $removed = $assessment->scores()
                ->whereNotIn('student_id', array_keys($wanted) ?: [0])
                ->delete();
        });

        return [
            'saved' => count($wanted),
            'removed' => $removed,
            'changed' => $changed,
        ];
    }

    /**
     * طلاب الشعبة مع درجة تسميعهم إن وُجدت — شبكة الاختيار.
     *
     * `participating` تقول صراحةً من له صفّ: الشاشة تؤشّره، ومن لا صفّ له
     * يبقى غير مؤشَّر ولا يُكتب له شيء.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function roster(Assessment $assessment, Section $section): Collection
    {
        $students = Student::query()
            ->inSection($section->id)
            ->with('currentEnrollment.section.grade')
            ->orderBy('first_name')
            ->get();

        $scores = $assessment->exists
            ? $assessment->scores()->get()->keyBy('student_id')
            : collect();

        return $students->map(function (Student $student) use ($scores) {
            $score = $scores->get($student->id);

            return [
                'student' => $student,
                'participating' => $score !== null,
                // رقم مجرّد لا كائن — نفس عقد شبكة الدرجات.
                'score' => $score ? self::plain($score->score) : null,
            ];
        });
    }

    /** «85.00» ← «85»: الأستاذ كتب ٨٥ فلا يُعاد إليه بأصفارٍ لم يكتبها. */
    public static function plain(mixed $score): ?string
    {
        if ($score === null) {
            return null;
        }

        $text = (string) $score;

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    /** الدرجات المكتوبة لهذه الجلسة — لمن حضروا وحدهم. */
    public static function participants(Assessment $assessment): Collection
    {
        return $assessment->scores()
            ->whereNotNull('score')
            ->with(['student.guardians.user'])
            ->get()
            ->filter(fn (GradeScore $score) => $score->student !== null)
            ->values();
    }
}
