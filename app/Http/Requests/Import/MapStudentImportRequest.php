<?php

namespace App\Http\Requests\Import;

use App\Models\StudentImport;
use Illuminate\Foundation\Http\FormRequest;

class MapStudentImportRequest extends FormRequest
{
    public function rules(): array
    {
        $import = $this->route('import');
        $lastColumn = count($import->headers ?? []) - 1;

        return [
            'mapping' => ['required', 'array', 'min:1'],
            'mapping.*' => ['required', 'integer', 'min:0', 'max:'.max($lastColumn, 0)],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $mapping = $this->input('mapping', []);

            foreach (array_keys($mapping) as $field) {
                if (! array_key_exists($field, StudentImport::FIELDS)) {
                    $validator->errors()->add("mapping.$field", __('messages.import.unknown_field'));
                }
            }

            // Every required system field has to be mapped to a column.
            foreach (StudentImport::FIELDS as $field => $required) {
                if ($required && ! isset($mapping[$field])) {
                    $validator->errors()->add('mapping', __('messages.import.missing_required_field', [
                        'field' => __('import_fields.'.$field),
                    ]));
                }
            }
        });
    }
}
