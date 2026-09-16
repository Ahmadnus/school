<?php

namespace App\Http\Requests\Fee;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * فورم «تسجيل دفعة».
 *
 * الفحص النهائي للمبلغ ليس هنا بل داخل PaymentRecorder تحت قفل الخطة: أي فحص
 * هنا يقرأ رصيداً قد يتغيّر قبل الكتابة. ما هنا ردّ سريع ومفهوم للمستخدم، لا
 * خط الدفاع.
 */
class StoreFeePaymentRequest extends FormRequest
{
    public function rules(): array
    {
        $plan = $this->route('fee_plan');

        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            // لا قبض بتاريخ مستقبلي: يقلب ترتيب الكشف ويخفي متأخرات اليوم.
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'method' => ['nullable', Rule::enum(PaymentMethod::class)],
            // اختياري: يبدأ التوزيع من هذا القسط، والباقي يكمل على الأقدم فالأقدم.
            'installment_id' => [
                'nullable',
                Rule::exists('fee_plan_installments', 'id')->where('fee_plan_id', $plan->id),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'reference' => ['nullable', 'string', 'max:100'],
            // يرسله التطبيق ليحمي من التسجيل المزدوج عند إعادة الإرسال.
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ];
    }
}
