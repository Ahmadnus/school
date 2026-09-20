<?php

namespace App\Services;

use App\Enums\FeePlanStatus;
use App\Enums\PaymentMethod;
use App\Models\FeePayment;
use App\Models\FeePlan;
use App\Models\FeeReceiptCounter;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * تسجيل الدفعات وإلغاؤها.
 *
 * كل عملية داخل معاملة واحدة تبدأ بقفل صف الخطة، والتحقق من المتبقّي يجري
 * **داخل** القفل لا قبله. بلا هذا القفل يستطيع أمينان يقبضان في اللحظة نفسها
 * أن يمرّا معاً من فحص «المبلغ أكبر من المتبقّي» فيدخل الصندوق أكثر من
 * المستحق ولا يظهر الخلل إلا في الجرد.
 *
 * التوزيع تلقائي: الأمين يكتب المبلغ فقط، والنظام يغطّي الأقساط من الأقدم
 * استحقاقاً، فلا يبقى قرار يدوي يمكن أن يُخطئ فيه.
 */
class PaymentRecorder
{
    /**
     * @param  int|null  $installmentId  قسط بعينه يبدأ منه التوزيع؛ null = الأقدم أولاً.
     */
    public static function record(
        FeePlan $plan,
        Money $amount,
        Carbon $paidOn,
        User $recordedBy,
        PaymentMethod $method = PaymentMethod::Cash,
        ?int $installmentId = null,
        ?string $description = null,
        ?string $reference = null,
        ?string $idempotencyKey = null,
    ): FeePayment {
        $schoolId = $plan->student->school_id;

        $receipt = DB::transaction(function () use (
            $plan, $amount, $paidOn, $recordedBy, $method,
            $installmentId, $description, $reference, $idempotencyKey, $schoolId
        ) {
            // إعادة إرسال الطلب نفسه (ضغطة مزدوجة، أو إعادة محاولة بعد انقطاع
            // الشبكة) تعيد الإيصال الأول بدل أن تقبض المبلغ مرتين.
            if ($idempotencyKey !== null) {
                $existing = FeePayment::query()
                    ->where('school_id', $schoolId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing) {
                    return $existing;
                }
            }

            $plan = FeePlan::query()->lockForUpdate()->findOrFail($plan->id);

            if ($plan->status === FeePlanStatus::Cancelled) {
                throw ValidationException::withMessages(['amount' => __('messages.fee_plan.cancelled')]);
            }

            if (! $amount->isPositive()) {
                throw ValidationException::withMessages(['amount' => __('messages.fee_payment.must_be_positive')]);
            }

            $remaining = $plan->remainingAmount();

            if ($amount->greaterThan($remaining)) {
                throw ValidationException::withMessages([
                    'amount' => __('messages.fee_payment.exceeds_remaining', ['remaining' => $remaining->toDecimal()]),
                ]);
            }

            $payment = FeePayment::create([
                'school_id' => $schoolId,
                'fee_plan_id' => $plan->id,
                'receipt_number' => FeeReceiptCounter::reserve($schoolId),
                'paid_on' => $paidOn,
                'amount_minor' => $amount,
                'method' => $method,
                'description' => $description,
                'reference' => $reference,
                'idempotency_key' => $idempotencyKey,
                'recorded_by' => $recordedBy->id,
            ]);

            self::allocate($plan, $payment, $amount, $installmentId);

            return $payment;
        });

        // خارج المعاملة عمداً: إشعارٌ عن دفعةٍ تراجعت لاحقاً كذبةٌ لا تُسحب.
        //
        // و`wasRecentlyCreated` شرطٌ لا تجميل: إعادة إرسال الطلب نفسه تعيد
        // الإيصال الأول (أعلاه)، فبدونه يصل الأهل إشعاران بدفعةٍ واحدة.
        if ($receipt->wasRecentlyCreated) {
            FeeNotifier::paymentRecorded($receipt);
        }

        return $receipt;
    }

    /**
     * توزيع المبلغ على الأقساط: القسط المختار أولاً إن وُجد، ثم الباقي على
     * الأقساط بترتيب الاستحقاق. أي فائض (لا يحدث ما دام المبلغ ≤ المتبقّي)
     * يُسجَّل صفَّ توزيع بلا قسط بدل أن يختفي.
     */
    private static function allocate(FeePlan $plan, FeePayment $payment, Money $amount, ?int $installmentId): void
    {
        $installments = $plan->installments()
            ->with('activeAllocations')
            ->orderBy('due_date')
            ->orderBy('sort_order')
            ->get();

        if ($installmentId !== null) {
            $chosen = $installments->firstWhere('id', $installmentId);

            if ($chosen) {
                $installments = $installments->reject(fn ($i) => $i->id === $chosen->id)->prepend($chosen);
            }
        }

        $left = $amount;

        foreach ($installments as $installment) {
            if (! $left->isPositive()) {
                break;
            }

            $share = $left->min($installment->remainingAmount());

            if (! $share->isPositive()) {
                continue;
            }

            $payment->allocations()->create([
                'fee_plan_installment_id' => $installment->id,
                'amount_minor' => $share,
            ]);

            $left = $left->minus($share);
        }

        if ($left->isPositive()) {
            $payment->allocations()->create([
                'fee_plan_installment_id' => null,
                'amount_minor' => $left,
            ]);
        }
    }

    /**
     * الإلغاء لا يحذف: الإيصال يبقى برقمه وسببه ومن ألغاه، والأرصدة تتجاهله
     * لأن الاستعلامات كلها تستثني `voided_at`. هكذا لا يختفي مبلغ من الدفاتر
     * بلا أثر يشرحه.
     */
    public static function void(FeePayment $payment, User $by, string $reason): FeePayment
    {
        return DB::transaction(function () use ($payment, $by, $reason) {
            $payment = FeePayment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($payment->isVoided()) {
                throw ValidationException::withMessages(['payment' => __('messages.fee_payment.already_voided')]);
            }

            $payment->forceFill([
                'voided_at' => now(),
                'voided_by' => $by->id,
                'void_reason' => $reason,
            ])->save();

            return $payment;
        });
    }

    /**
     * إعادة توزيع كل دفعات الخطة من الصفر. تُستدعى بعد تعديل جدول الأقساط،
     * حتى لا يبقى توزيع معلّقاً على قسط تغيّر مبلغه أو حُذف.
     */
    public static function reallocate(FeePlan $plan): void
    {
        DB::transaction(function () use ($plan) {
            $payments = $plan->activePayments()->orderBy('paid_on')->orderBy('id')->get();

            foreach ($payments as $payment) {
                $payment->allocations()->delete();
            }

            foreach ($payments as $payment) {
                self::allocate($plan->fresh(), $payment, $payment->amount(), null);
            }
        });
    }
}
