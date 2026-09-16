<?php

namespace App\Http\Requests\Fee;

use App\Models\AcademicYear;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * مسارا T2 ينتهيان هنا: «تعيين الرسوم» يختار نوع قسط، وطبقة أنواع الأقساط في
 * فورم الطالب تملأ الحقول نفسها. في الحالتين الخطة نسخة (قرار 2-أ).
 */
class StoreFeePlanRequest extends FormRequest
{
    public function rules(): array
    {
        $schoolId = $this->user()->school_id;

        return [
            'student_id' => [
                'required',
                Rule::exists('students', 'id')->where('school_id', $schoolId),
            ],
            'academic_year_id' => [
                'required',
                Rule::exists('academic_years', 'id')->where('school_id', $schoolId),
            ],
            // null يعني خطة مخصّصة، وعندها تحمل أرقامها بنفسها.
            'fee_type_id' => [
                'nullable',
                Rule::exists('fee_types', 'id')->where('school_id', $schoolId),
            ],
            'total_amount' => ['required_without:fee_type_id', 'nullable', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            // الخصم بلا سبب لا يمكن مراجعته لاحقاً.
            'discount_reason' => ['nullable', 'string', 'max:300', 'required_with:discount_amount'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'payment_reminders' => ['nullable', 'boolean'],

            'installments' => ['nullable', 'array'],
            'installments.*.due_date' => ['required', 'date'],
            'installments.*.amount' => ['required', 'numeric', 'min:0'],
            'installments.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('academic_year_id')) {
            $this->merge([
                'academic_year_id' => AcademicYear::currentFor($this->user()->school_id)?->id,
            ]);
        }

        // خصم صفري لا يحتاج سبباً؛ تفريغه هنا يمنع required_with من الاعتراض.
        //
        // يُضبط إلى null لا يُحذف بـ `$this->request->remove()`: حقيبة `request`
        // تحمل بيانات النماذج وحدها، أمّا بدن JSON فيعيش في حقيبة `json` — فكان
        // الحذف لا يمسّ شيئاً ويرتدّ كل طلب من التطبيق (وهو يرسل JSON دائماً)
        // بـ 422 يطالب بسبب خصم لا وجود له — فلا تُنشأ خطة رسوم واحدة أبداً.
        // و`required_with` لا يعتبر null حضوراً، فالتفريغ يكفي ويعمل في الحقيبتين.
        if ($this->has('discount_amount') && (float) $this->input('discount_amount') <= 0) {
            $this->merge(['discount_amount' => null]);
        }
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $total = $this->filled('total_amount')
                ? Money::fromDecimal($this->input('total_amount'))
                : null;

            if ($total === null) {
                return;
            }

            // خصم يتجاوز الإجمالي يجعل الصافي سالباً، وعندها لا معنى لأي رصيد.
            // `input(..., 0)` لا ينفع هنا: المفتاح **موجود** وقيمته null بعد تفريغ
            // الخصم الصفري أعلاه، فالقيمة الافتراضية لا تُستعمل ويصل null إلى Money.
            if (Money::fromDecimal($this->input('discount_amount') ?? 0)->greaterThan($total)) {
                $validator->errors()->add('discount_amount', __('messages.fee_plan.discount_too_large'));
            }
        });
    }
}
