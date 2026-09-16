<?php

namespace App\Http\Requests\Academic;

use Illuminate\Foundation\Http\FormRequest;

/** Drag-and-drop reordering: an ordered list of ids. */
class ReorderRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'distinct'],
        ];
    }
}
