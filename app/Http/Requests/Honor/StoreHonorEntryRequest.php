<?php

namespace App\Http\Requests\Honor;

use App\Enums\HonorCategory;
use App\Models\Grade;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreHonorEntryRequest extends FormRequest
{
    public function rules(): array
    {
        $schoolId = $this->user()->school_id;

        return [
            'student_id' => ['required', Rule::exists('students', 'id')->where('school_id', $schoolId)],
            'subject_id' => [
                'nullable',
                Rule::exists('subjects', 'id')->whereIn(
                    'grade_id',
                    Grade::query()->select('id')->where('school_id', $schoolId),
                ),
            ],
            'category' => ['required', Rule::enum(HonorCategory::class)],
            // سبب التكريم هو ما يقرأه الأهالي على اللوحة، وهو ما يجعلها
            // تحفيزاً لا قائمة أسماء.
            'reason' => ['required', 'string', 'min:3', 'max:300'],
            'publish' => ['nullable', 'boolean'],
        ];
    }
}
