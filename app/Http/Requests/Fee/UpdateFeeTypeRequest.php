<?php

namespace App\Http\Requests\Fee;

use App\Enums\Status;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFeeTypeRequest extends FormRequest
{
    public function rules(): array
    {
        $type = $this->route('fee_type');
        $schoolId = $type->school_id;

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:150',
                Rule::unique('fee_types', 'name')->where('school_id', $schoolId)->ignore($type->id),
            ],
            'grade_id' => ['nullable', Rule::exists('grades', 'id')->where('school_id', $schoolId)],
            'subject_id' => ['nullable', 'integer'],
            'is_transport' => ['nullable', 'boolean'],
            'total_amount' => ['sometimes', 'required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_default' => ['nullable', 'boolean'],
            'status' => ['sometimes', Rule::enum(Status::class)],

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

            $total = $this->has('total_amount')
                ? Money::fromDecimal($this->input('total_amount'))
                : $this->route('fee_type')->totalAmount();

            $sum = Money::sum(
                collect($rows)->map(fn ($row) => Money::fromDecimal($row['amount'] ?? 0)),
            );

            if (! $sum->equals($total)) {
                $validator->errors()->add('installments', __('messages.fee_type.installments_mismatch'));
            }
        });
    }
}
