<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * علامة المادة من مئة — القاعدة الوحيدة، في مكان واحد.
 *
 * كانت القسمة مكرّرة في `ReportCardBuilder` و`StudentSubjects`: نسختان من
 * الحساب نفسه، تفترقان عند أول تعديل فتعرض الشاشة رقماً وتطبع الشهادة آخر.
 *
 * القاعدة كما تقولها المدرسة:
 *
 *   الكويزات ٢٠٪ · النصفي ٣٠٪ · النهائي ٥٠٪
 *
 * أي أن الوزن حصّة **النوع** لا حصّة الورقة: كويزان وزنهما معاً ٢٠٪، يؤخذ
 * متوسّطهما ثم يُضرب بالحصّة — لا ٢٠٪ لكلٍّ منهما. هذا ما يقصده المعلّم حين
 * يقول «الكويزات عشرون»، وهو ما كان النظام يخطئ فيه.
 *
 * ثلاث قواعد تحكم الحالات الناقصة، كلّها تخدم شيئاً واحداً: ألّا يُعاقَب
 * طالب على امتحان لم يُعقد بعد.
 *
 * 1. نوع بلا وزن مُعلَن يتقاسم ما تبقّى بالتساوي مع أمثاله.
 * 2. نوع لا علامة فيه بعد (لم يُصحَّح، أو لم يُعقد) يخرج من القسمة، ويُعاد
 *    توزيع وزنه على الموجود — فالطالب الذي أخذ ١٨/٢٠ في الكويز الوحيد
 *    المُصحَّح يرى ٩٠٪، لا ١٨٪ لأن النهائي لم يأتِ.
 * 3. وزن مكتوب على تقييم بعينه يعلو على وزن نوعه — استثناء يبقى ممكناً.
 */
class SubjectGrade
{
    /**
     * @param  Collection<int, object>  $assessments  تقييمات المادة، وعلاقة `type` محمّلة
     * @param  Collection<int, object>  $scoresByAssessment  العلامات مُفهرسة بمعرّف التقييم
     * @return float|null النسبة من مئة، أو `null` إن لم تُصحَّح أي ورقة
     */
    public static function percent(Collection $assessments, Collection $scoresByAssessment): ?float
    {
        $groups = [];

        foreach ($assessments as $assessment) {
            $score = $scoresByAssessment->get($assessment->id)?->score;

            if ($score === null) {
                continue;
            }

            $max = (float) $assessment->max_score;

            if ($max <= 0) {
                // مقام صفر: ورقة من صفر لا تعني شيئاً، ولا يجوز أن تُسقط
                // حساب المادة كلّها بقسمةٍ على صفر.
                continue;
            }

            $ratio = (float) $score / $max;

            // وزن الورقة نفسها يعلو؛ وإلّا فوزن نوعها؛ وإلّا فبلا وزن.
            $own = self::number($assessment->weight_percent);
            $typeWeight = self::number($assessment->type?->weight_percent);

            if ($own !== null) {
                // ورقة موزونة بذاتها تقف مجموعةً مستقلّة، فلا يبتلعها نوعها.
                $groups['assessment:'.$assessment->id] = ['weight' => $own, 'ratios' => [$ratio]];

                continue;
            }

            $key = 'type:'.($assessment->assessment_type_id ?? 0);
            $groups[$key] ??= ['weight' => $typeWeight, 'ratios' => []];
            $groups[$key]['ratios'][] = $ratio;
        }

        if ($groups === []) {
            return null;
        }

        $declared = array_filter($groups, fn (array $g) => $g['weight'] !== null);
        $undeclared = array_filter($groups, fn (array $g) => $g['weight'] === null);

        $declaredTotal = array_sum(array_map(fn (array $g) => $g['weight'], $declared));

        // ما تبقّى من المئة يتقاسمه غير المُعلَن؛ وإن لم يبقَ شيء (أو لم
        // يُعلَن وزن أصلاً) تساوى الجميع — وهو سلوك النظام قبل الأوزان.
        $share = 0.0;

        if ($undeclared !== []) {
            $remaining = max(0.0, 100.0 - $declaredTotal);
            $share = $remaining > 0
                ? $remaining / count($undeclared)
                : ($declared === [] ? 100.0 / count($undeclared) : 0.0);
        }

        $earned = 0.0;
        $total = 0.0;

        foreach ($groups as $group) {
            $weight = $group['weight'] ?? $share;

            if ($weight <= 0) {
                continue;
            }

            // متوسّط أوراق النوع: كويزان بـ٢٠٪ يعني متوسّطهما × ٢٠٪.
            $average = array_sum($group['ratios']) / count($group['ratios']);
            $earned += $average * $weight;
            $total += $weight;
        }

        if ($total <= 0) {
            return null;
        }

        // القسمة على الوزن **الحاضر** لا على مئة: النهائي الذي لم يُعقد بعد
        // لا يخفض العلامة، بل يخرج من الحساب حتى تصل علامته.
        return round(($earned / $total) * 100, 2);
    }

    /**
     * مجموع الأوزان المُعلَنة لأنواع المدرسة — لتقول الواجهة «٩٠٪ من ١٠٠».
     *
     * @param  Collection<int, object>  $types
     */
    public static function declaredTotal(Collection $types): float
    {
        return round(
            $types->sum(fn (object $type) => (float) (self::number($type->weight_percent) ?? 0)),
            2,
        );
    }

    private static function number(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $number = (float) $value;

        // صفر ليس وزناً: هو ما تكتبه قاعدة البيانات افتراضياً، وحسابه وزناً
        // يُسقط النوع من العلامة بصمت.
        return $number > 0 ? $number : null;
    }
}
