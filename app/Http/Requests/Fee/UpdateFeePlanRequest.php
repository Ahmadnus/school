<?php

namespace App\Http\Requests\Fee;

use App\Enums\FeePlanStatus;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFeePlanRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'total_amount' => ['sometimes', 'required', 'numeric', 'min:0'],
            'discount_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'discount_reason' => ['nullable', 'string', 'max:300'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'payment_reminders' => ['nullable', 'boolean'],
            'status' => ['sometimes', Rule::enum(FeePlanStatus::class)],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $plan = $this->route('fee_plan');

            $total = $this->has('total_amount')
                ? Money::fromDecimal($this->input('total_amount'))
                : $plan->totalAmount();

            $discount = $this->has('discount_amount')
                ? Money::fromDecimal($this->input('discount_amount') ?? 0)
                : $plan->discountAmount();

            if ($discount->greaterThan($total)) {
                $validator->errors()->add('discount_amount', __('messages.fee_plan.discount_too_large'));

                return;
            }

            // أهم فحص في التعديل: إنزال الصافي تحت المقبوض يجعل الطالب دافعاً
            // أكثر من المستحق، ويظهر الفرق في الدفاتر بلا مصدر. الطريق الصحيح
            // لردّ مبلغ هو إلغاء إيصاله لا تصغير الخطة.
            $paid = $plan->paidAmount();
            $net = $total->minus($discount);

            if ($net->lessThan($paid)) {
                $validator->errors()->add('total_amount', __('messages.fee_plan.below_paid', [
                    'paid' => $paid->toDecimal(),
                ]));
            }

            if ($discount->isPositive() && ! $this->filled('discount_reason') && ! $plan->discount_reason) {
                $validator->errors()->add('discount_reason', __('messages.fee_plan.discount_reason_required'));
            }

            // إلغاء خطة عليها مقبوضات يُخفي مبلغاً محصَّلاً من التقارير.
            if ($this->input('status') === FeePlanStatus::Cancelled->value && $paid->isPositive()) {
                $validator->errors()->add('status', __('messages.fee_plan.cancel_with_payments'));
            }
        });
    }
}
