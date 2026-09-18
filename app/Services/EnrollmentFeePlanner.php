<?php

namespace App\Services;

use App\Models\FeePlan;
use App\Models\FeeType;
use App\Models\StudentEnrollment;
use App\Support\Money;

/**
 * خطة الرسوم تتبع التسجيل تلقائياً.
 *
 * الطالب يُسند إلى صف، فيصير عليه قسط ذلك الصف — بلا أن يفتح أحد شاشة
 * «تعيين الرسوم» لكل طالب على حدة. وهذا ليس تسهيلاً فحسب: الطالب المسجَّل
 * بلا خطة لا يظهر في أي تقرير مالي، فيُنسى حتى يحلّ موعد التحصيل ويُكتشف
 * أن صفّاً كاملاً بلا أقساط.
 *
 * تُنادى من كل مسار يُنشئ تسجيلاً — الإنشاء المفرد، والتسجيل من ملف الطالب،
 * والترفيع الجماعي، والاستيراد من ملف.
 */
class EnrollmentFeePlanner
{
    /**
     * تُنشئ خطة الصف لهذا التسجيل إن لم تكن موجودة.
     *
     * **آمنة للتكرار**: نداؤها مرّتين لا يضاعف القسط. وهذا شرط لا زينة —
     * الترفيع الجماعي والاستيراد قد يمرّان على الطالب نفسه أكثر من مرّة،
     * ومضاعفة القسط خطأ مالي لا يُكتشف إلا من وليّ الأمر.
     *
     * تُرجع `null` حين لا شيء يُفعَل: لا نوع افتراضي للصف، أو الخطة قائمة،
     * أو المبلغ صفر. وخطة بصفر ليست خطة — رقم كاذب في التقارير أسوأ من غيابه.
     */
    public static function ensureFor(StudentEnrollment $enrollment): ?FeePlan
    {
        $enrollment->loadMissing('section', 'student');

        $section = $enrollment->section;
        $student = $enrollment->student;

        if (! $section || ! $student) {
            return null;
        }

        $type = self::defaultTypeFor($student->school_id, $section->grade_id);

        if (! $type) {
            return null;
        }

        $total = $type->totalAmount();

        if ($total->isZero()) {
            return null;
        }

        $exists = FeePlan::query()
            ->where('student_id', $student->id)
            ->where('academic_year_id', $enrollment->academic_year_id)
            ->where('fee_type_id', $type->id)
            ->exists();

        if ($exists) {
            return null;
        }

        return FeePlanBuilder::create(
            studentId: $student->id,
            academicYearId: $enrollment->academic_year_id,
            type: $type,
            totalAmount: $total,
            discountAmount: Money::zero(),
            installments: null,
            discountReason: null,
            notes: null,
            paymentReminders: true,
        );
    }

    /** النوع الافتراضي للصف — واحد لا أكثر، يفرضه `FeeTypeController`. */
    public static function defaultTypeFor(int $schoolId, ?int $gradeId): ?FeeType
    {
        return FeeType::query()
            ->with('installments')
            ->ofSchool($schoolId)
            ->where('grade_id', $gradeId)
            ->where('is_default', true)
            ->first();
    }
}
