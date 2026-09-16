<?php

namespace App\Http\Requests\Fee;

use Illuminate\Foundation\Http\FormRequest;

/** الإلغاء يشترط سبباً: إيصال يختفي أثره بلا تفسير هو ثقب في الدفاتر. */
class VoidFeePaymentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
