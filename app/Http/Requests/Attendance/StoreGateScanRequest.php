<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

/** The scan panel posts uids as they are read; unknown ones are logged too. */
class StoreGateScanRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'nfc_uid' => ['required', 'string', 'max:100'],
            'scanned_at' => ['nullable', 'date'],
            'date' => ['nullable', 'date'],
        ];
    }
}
