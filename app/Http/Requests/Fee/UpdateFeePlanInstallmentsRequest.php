<?php

namespace App\Http\Requests\Fee;

use Illuminate\Foundation\Http\FormRequest;

/**
 * تعديل جدول أقساط خطة قائمة — لم يكن ممكناً من قبل، فكان تصحيح تاريخ استحقاق
 * واحد يوجب حذف الخطة وإعادة إنشائها، وهو ما يمحو إيصالاتها.
 *
 * المجموع يُفحص في FeePlanBuilder مقابل الصافي.
 */
class UpdateFeePlanInstallmentsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'installments' => ['present', 'array'],
            'installments.*.due_date' => ['required', 'date'],
            'installments.*.amount' => ['required', 'numeric', 'min:0.01'],
            'installments.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
