<?php

namespace App\Http\Requests\Post;

use Illuminate\Foundation\Http\FormRequest;

class ReviewPostRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'decision' => ['required', 'in:approved,rejected'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
