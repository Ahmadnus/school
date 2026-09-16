<?php

namespace App\Http\Requests\Fee;

use App\Enums\Status;
use App\Models\Grade;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFeeTypeRequest extends FormRequest
{
    public function rules(): array
    {
        $schoolId = $this->user()->school_id;

        return [
            'name' => [
                'required', 'string', 'max:150',
                Rule::unique('fee_types', 'name')->where('school_id', $schoolId),
            ],
            // Null is the form's "no grade" default.
            'grade_id' => ['nullable', Rule::exists('grades', 'id')->where('school_id', $schoolId)],
            'subject_id' => [
                'nullable',
                Rule::exists('subjects', 'id')->whereIn(
                    'grade_id',
                    Grade::query()->select('id')->where('school_id', $schoolId),
                ),
            ],
            'is_transport' => ['nullable', 'boolean'],
            'total_amount' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_default' => ['nullable', 'boolean'],
            'status' => ['nullable', Rule::enum(Status::class)],

            // The instalment table of the form; it may start empty.
            'installments' => ['nullable', 'array'],
            'installments.*.due_date' => ['required', 'date'],
            'installments.*.amount' => ['required', 'numeric', 'min:0'],
            'installments.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $rows = $this->input('installments');

            if (! $rows) {
                return;
            }

            // سطر «المجموع: س من ص» الأخضر في الفورم يجب أن يتوازن فعلاً.
            // المقارنة على الفلس بلا هامش تسامح: فرق فلس واحد فرق حقيقي.
            $sum = Money::sum(
                collect($rows)->map(fn ($row) => Money::fromDecimal($row['amount'] ?? 0)),
            );

            if (! $sum->equals(Money::fromDecimal($this->input('total_amount', 0)))) {
                $validator->errors()->add('installments', __('messages.fee_type.installments_mismatch'));
            }
        });
    }
}
