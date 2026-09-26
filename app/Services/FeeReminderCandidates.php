<?php

namespace App\Services;

use App\Enums\FeePlanStatus;
use App\Models\FeePlan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * من يستحقّ تذكيراً بالدفع، ومن لا يستحقّ.
 *
 * التذكير الجماعي فعلٌ حسّاس: رسالةٌ إلى أبٍ سدّد أمس تُفقد المدرسة
 * مصداقيّتها، وتُدرّب الباقين على تجاهل تذكيراتها. فالقائمة تُبنى بقاعدتين
 * صريحتين، وتُعرَض قبل الإرسال ليراجعها بشر:
 *
 * 1. عليه متبقٍّ فعلاً (بعد الخصم وبعد كل دفعة غير ملغاة).
 * 2. ومضى على آخر دفعةٍ أكثر من المدّة المحدّدة — أو لم يدفع شيئاً ومضى
 *    على فتح خطّته تلك المدّة.
 *
 * والخطة الملغاة تخرج: لا يُطالَب أحدٌ بمالٍ أُلغي.
 */
class FeeReminderCandidates
{
    /**
     * @return Collection<int, array{
     *     student_id:int, student_name:string, placement:?string,
     *     total:string, paid:string, remaining:string,
     *     last_payment_on:?string, days_since:int, guardians:int
     * }>
     */
    public static function for(int $schoolId, int $days = 30): Collection
    {
        $cutoff = Carbon::today()->subDays($days);

        return FeePlan::query()
            ->whereHas('student', fn ($q) => $q->where('school_id', $schoolId))
            ->where('status', '!=', FeePlanStatus::Cancelled)
            ->with([
                'student.currentEnrollment.section.grade',
                'student.guardians.user',
                'activePayments',
            ])
            ->get()
            ->map(function (FeePlan $plan) use ($cutoff) {
                $remaining = $plan->remainingAmount();

                if (! $remaining->isPositive()) {
                    return null;
                }

                $last = $plan->activePayments
                    ->sortByDesc(fn ($payment) => $payment->paid_on)
                    ->first();

                // بلا دفعة: يُقاس من فتح الخطة، فطالبٌ سُجّل اليوم لا
                // يُطالَب غداً.
                $since = $last?->paid_on ?? $plan->created_at;

                if ($since->gt($cutoff)) {
                    return null;
                }

                $student = $plan->student;

                return [
                    'student_id' => $student->id,
                    'student_name' => $student->full_name,
                    'placement' => $student->currentEnrollment?->section
                        ? trim(
                            ($student->currentEnrollment->section->grade?->name ?? '')
                            .' - '.$student->currentEnrollment->section->name,
                            ' -',
                        )
                        : null,
                    'total' => (string) $plan->netAmount()->toDecimal(),
                    'paid' => (string) $plan->paidAmount()->toDecimal(),
                    'remaining' => (string) $remaining->toDecimal(),
                    'last_payment_on' => $last?->paid_on?->format('Y-m-d'),
                    'days_since' => (int) $since->diffInDays(Carbon::today()),
                    // بلا وليّ أمر له حساب لا يصل التذكير؛ تُعرَض الحقيقة
                    // بدل أن يُحسَب مُرسَلاً.
                    'guardians' => $student->guardians
                        ->filter(fn ($guardian) => $guardian->user !== null)
                        ->count(),
                ];
            })
            ->filter()
            ->sortByDesc('days_since')
            ->values();
    }
}
