<?php

namespace App\Http\Requests\Message;

use Illuminate\Foundation\Http\FormRequest;

class StoreMessageRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // A message is text, files, or both — never neither.
            'body' => ['nullable', 'string', 'max:10000', 'required_without:files'],
            'files' => ['nullable', 'array', 'max:5'],
            'files.*' => ['file', 'max:10240'],
        ];
    }
}
