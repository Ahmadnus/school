<?php

namespace App\Services;

use App\Models\FeePlan;
use App\Models\FeeType;
use App\Models\FeeTypeInstallment;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * إنشاء خطة الرسوم وكتابة جدول أقساطها.
 *
 * الخطة نسخة لا مرجع (قرار 2-أ): المبلغ وكل قسط يُنسخان لحظة الإنشاء، فتعديل
 * النوع لاحقاً لا يمسّها.
 *
 * والثابت الذي يحرسه هذا الملف: **مجموع أقساط الخطة = الصافي** (الإجمالي ناقص
 * الخصم). لو بقيت الأقساط على قيمة الإجمالي بعد خصم، لظهر الطالب مسدِّداً
 * بالكامل وفي جدوله أقساط ناقصة — فرق يساوي الخصم بالضبط لا يفسّره شيء.
 * لذلك عند وجود خصم تُصغَّر الأقساط المنسوخة نسبياً بتوزيع يحفظ المجموع فلساً
 * بفلس.
 */
class FeePlanBuilder
{
    /**
     * @param  array<int, array<string, mixed>>|null  $installments
     *                                                               أقساط كتبها المستخدم؛ null يعني نسخ أقساط النوع.
     */
    public static function create(
        int $studentId,
        int $academicYearId,
        ?FeeType $type,
        Money $totalAmount,
        Money $discountAmount,
        ?array $installments = null,
        ?string $discountReason = null,
        ?string $notes = null,
        bool $paymentReminders = true,
    ): FeePlan {
        return DB::transaction(function () use (
            $studentId, $academicYearId, $type, $totalAmount,
            $discountAmount, $installments, $discountReason, $notes, $paymentReminders
        ) {
            $plan = FeePlan::create([
                'student_id' => $studentId,
                'academic_year_id' => $academicYearId,
                'fee_type_id' => $type?->id,
                'total_minor' => $totalAmount,
                'discount_minor' => $discountAmount,
                'discount_reason' => $discountReason,
                'notes' => $notes,
                'payment_reminders' => $paymentReminders,
            ]);

            $rows = $installments !== null
                ? self::normalise($installments)
                : self::rowsFromType($type);

            self::writeInstallments($plan, $rows, scaleToNet: $installments === null);

            return $plan;
        });
    }

    /**
     * كتابة جدول الأقساط مع فرض الثابت: المجموع = الصافي.
     *
     * $scaleToNet=true تُصغّر الصفوف المنسوخة من النوع لتطابق الصافي.
     * $scaleToNet=false تعني صفوفاً كتبها المستخدم، فالمجموع يُفحص ويُرفض إن
     * لم يطابق — لا يُعدَّل من تحت يده بلا علمه.
     *
     * @param  array<int, array{due_date: string, amount_minor: Money, notes: ?string}>  $rows
     */
    public static function writeInstallments(FeePlan $plan, array $rows, bool $scaleToNet = false): void
    {
        DB::transaction(function () use ($plan, $rows, $scaleToNet) {
            $net = $plan->netAmount();

            if ($rows !== []) {
                if ($scaleToNet) {
                    $shares = Money::distribute($net, array_map(fn ($row) => $row['amount_minor']->minor, $rows));

                    foreach (array_keys($rows) as $index) {
                        $rows[$index]['amount_minor'] = $shares[$index];
                    }
                }

                $sum = Money::sum(array_map(fn ($row) => $row['amount_minor'], $rows));

                if (! $sum->equals($net)) {
                    throw ValidationException::withMessages([
                        'installments' => __('messages.fee_plan.installments_mismatch', [
                            'sum' => $sum->toDecimal(),
                            'net' => $net->toDecimal(),
                        ]),
                    ]);
                }
            }

            $plan->installments()->delete();

            foreach (array_values($rows) as $position => $row) {
                $plan->installments()->create([
                    'sort_order' => $position + 1,
                    'due_date' => $row['due_date'],
                    'amount_minor' => $row['amount_minor'],
                    'notes' => $row['notes'] ?? null,
                ]);
            }

            // حذف الصفوف أخذ معه توزيعات الدفعات؛ تُعاد على الجدول الجديد حتى
            // يبقى مجموع توزيعات كل دفعة مساوياً لمبلغها.
            PaymentRecorder::reallocate($plan->fresh());
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array{due_date: string, amount_minor: Money, notes: ?string}>
     */
    public static function normalise(array $rows): array
    {
        return array_map(fn (array $row) => [
            'due_date' => $row['due_date'],
            'amount_minor' => Money::fromDecimal($row['amount'] ?? 0),
            'notes' => $row['notes'] ?? null,
        ], array_values($rows));
    }

    /** @return array<int, array{due_date: string, amount_minor: Money, notes: ?string}> */
    private static function rowsFromType(?FeeType $type): array
    {
        if (! $type) {
            return [];
        }

        return $type->installments
            ->map(fn (FeeTypeInstallment $installment) => [
                'due_date' => $installment->due_date->toDateString(),
                'amount_minor' => $installment->amount(),
                'notes' => $installment->notes,
            ])
            ->all();
    }
}
