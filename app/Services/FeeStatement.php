<?php

namespace App\Services;

use App\Models\FeePayment;
use App\Models\FeePlan;
use App\Models\Student;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * كشف حساب الطالب: كل ما زاد عليه وكل ما سدّده، بترتيب زمني ورصيد جارٍ بعد
 * كل سطر.
 *
 * وجوده هو الفرق بين «الرقم يقول 1200» و«هذه الأسطر التي تُخرج 1200». من دونه
 * لا يستطيع أحد أن يراجع خلافاً مع وليّ أمر، ومن معه يستطيع أي موظف أن يشرح
 * الرصيد سطراً سطراً بلا حاسبة.
 */
class FeeStatement
{
    /**
     * @return array{
     *   student_id: int,
     *   billed: Money, discount: Money, net: Money, paid: Money, remaining: Money,
     *   lines: Collection<int, array<string, mixed>>
     * }
     */
    public static function forStudent(Student $student): array
    {
        $plans = $student->feePlans()
            ->with(['academicYear', 'installments', 'activePayments', 'payments'])
            ->get();

        $lines = collect();

        foreach ($plans as $plan) {
            $lines->push([
                'date' => $plan->created_at?->toDateString(),
                'kind' => 'charge',
                'description' => $plan->academicYear?->name
                    ? __('fees.charge_for_year', ['year' => $plan->academicYear->name])
                    : __('fees.charge'),
                'fee_plan_id' => $plan->id,
                'debit' => $plan->totalAmount(),
                'credit' => Money::zero(),
            ]);

            if ($plan->hasDiscount()) {
                $lines->push([
                    'date' => $plan->created_at?->toDateString(),
                    'kind' => 'discount',
                    'description' => $plan->discount_reason ?: __('fees.discount'),
                    'fee_plan_id' => $plan->id,
                    'debit' => Money::zero(),
                    'credit' => $plan->discountAmount(),
                ]);
            }

            foreach ($plan->payments as $payment) {
                $lines->push([
                    'date' => $payment->paid_on?->toDateString(),
                    'kind' => $payment->isVoided() ? 'voided_payment' : 'payment',
                    'description' => $payment->description ?: __('fees.receipt_no', ['number' => $payment->receipt_number]),
                    'fee_plan_id' => $plan->id,
                    'receipt_number' => $payment->receipt_number,
                    'method' => $payment->method->value,
                    'void_reason' => $payment->void_reason,
                    'debit' => Money::zero(),
                    // الإيصال الملغى يظهر في الكشف ولا يُحتسب: أثره محفوظ،
                    // وتأثيره على الرصيد صفر.
                    'credit' => $payment->isVoided() ? Money::zero() : $payment->amount(),
                ]);
            }
        }

        $ordered = $lines->sortBy([['date', 'asc'], ['kind', 'asc']])->values();

        $balance = Money::zero();

        $ordered = $ordered->map(function (array $line) use (&$balance) {
            $balance = $balance->plus($line['debit'])->minus($line['credit']);

            return [
                ...$line,
                'debit' => $line['debit']->toDecimal(),
                'credit' => $line['credit']->toDecimal(),
                'balance' => $balance->toDecimal(),
            ];
        });

        $billed = Money::sum($plans->map(fn (FeePlan $p) => $p->totalAmount()));
        $discount = Money::sum($plans->map(fn (FeePlan $p) => $p->discountAmount()));
        $paid = Money::sum($plans->map(fn (FeePlan $p) => $p->paidAmount()));

        return [
            'student_id' => $student->id,
            'billed' => $billed,
            'discount' => $discount,
            'net' => $billed->minus($discount),
            'paid' => $paid,
            'remaining' => $billed->minus($discount)->minus($paid)->clampToZero(),
            'lines' => $ordered,
        ];
    }

    /**
     * لوحة الأرقام للمدرسة: المفوتر، المخصوم، المحصَّل، المتبقّي، والمتأخر —
     * كلها من المصدر نفسه فلا يختلف رقم عن آخر بين شاشتين.
     *
     * @return array<string, mixed>
     */
    public static function summary(int $schoolId, ?int $academicYearId = null): array
    {
        $plans = FeePlan::query()
            ->ofSchool($schoolId)
            ->when($academicYearId, fn ($q) => $q->where('academic_year_id', $academicYearId))
            ->with(['installments.activeAllocations', 'activePayments'])
            ->get();

        $billed = Money::sum($plans->map(fn (FeePlan $p) => $p->totalAmount()));
        $discount = Money::sum($plans->map(fn (FeePlan $p) => $p->discountAmount()));
        $paid = Money::sum($plans->map(fn (FeePlan $p) => $p->paidAmount()));
        $net = $billed->minus($discount);

        $overdue = Money::zero();
        $overduePlans = 0;

        foreach ($plans as $plan) {
            $planOverdue = Money::sum(
                $plan->installments
                    ->filter(fn ($i) => $i->isOverdue())
                    ->map(fn ($i) => $i->remainingAmount()),
            );

            if ($planOverdue->isPositive()) {
                $overdue = $overdue->plus($planOverdue);
                $overduePlans++;
            }
        }

        $discounted = $plans->filter(fn (FeePlan $p) => $p->hasDiscount());

        return [
            'billed' => $billed->toDecimal(),
            'discount' => $discount->toDecimal(),
            'net' => $net->toDecimal(),
            'collected' => $paid->toDecimal(),
            'outstanding' => $net->minus($paid)->clampToZero()->toDecimal(),
            'overdue' => $overdue->toDecimal(),
            'plans_count' => $plans->count(),
            'overdue_plans_count' => $overduePlans,
            'discounted_plans_count' => $discounted->count(),
            'discounted_students_count' => $discounted->pluck('student_id')->unique()->count(),
            // فحص الاتساق: مجموع مبالغ الإيصالات غير الملغاة يجب أن يساوي
            // مجموع توزيعاتها. اختلافهما يعني فلساً ضائعاً بين الجدولين.
            'balanced' => self::allocationsBalance($schoolId),
        ];
    }

    /** هل كل دفعة محتسبة موزَّعة بالكامل؟ */
    public static function allocationsBalance(int $schoolId): bool
    {
        $paid = (int) FeePayment::query()->ofSchool($schoolId)->active()->sum('amount_minor');

        $allocated = (int) FeePayment::query()
            ->ofSchool($schoolId)
            ->active()
            ->join('fee_payment_allocations', 'fee_payment_allocations.fee_payment_id', '=', 'fee_payments.id')
            ->sum('fee_payment_allocations.amount_minor');

        return $paid === $allocated;
    }
}
