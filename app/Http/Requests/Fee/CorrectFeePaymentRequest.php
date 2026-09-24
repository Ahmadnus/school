<?php

namespace App\Http\Requests\Fee;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * فورم «تصحيح دفعة».
 *
 * الحقول هي حقول التسجيل نفسها لأن التصحيح إصدارٌ جديد لا تحديث حقل: ما يُرسل
 * هو الإيصال الصحيح كاملاً، لا الفرق. و`installment_id` مقيَّد بخطة الإيصال
 * المصحَّح حتى لا ينتقل مبلغ إلى خطة أخرى تحت اسم التصحيح.
 */
class CorrectFeePaymentRequest extends FormRequest
{
    public function rules(): array
    {
        $planId = $this->route('payment')->fee_plan_id;

        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'installment_id' => [
                'nullable',
                Rule::exists('fee_plan_installments', 'id')->where('fee_plan_id', $planId),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'reference' => ['nullable', 'string', 'max:100'],
            // سبب التصحيح اختياري: الغالب «رقم خطأ»، ولها نصّ افتراضي.
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
