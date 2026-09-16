<?php

namespace App\Http\Requests\Honor;

use App\Enums\HonorCategory;
use App\Models\Grade;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * تكريم دفعة واحدة — «الأوائل الثلاثة في كل شعبة» في طلب واحد.
 *
 * السبب والصنف مشتركان للدفعة كلها: هذا هو الاستعمال الفعلي (تكريم آخر الفصل
 * لمجموعة)، ولو لزم سبب خاص بطالب فـ `store` المفرد ما زال قائماً.
 */
class BulkStoreHonorEntryRequest extends FormRequest
{
    public function rules(): array
    {
        $schoolId = $this->user()->school_id;

        return [
            // حدّ أعلى معقول: الدفعة فعل بشري، لا استيراد جماعي.
            'student_ids' => ['required', 'array', 'min:1', 'max:60'],
            'student_ids.*' => [
                Rule::exists('students', 'id')->where('school_id', $schoolId),
            ],
            'subject_id' => [
                'nullable',
                Rule::exists('subjects', 'id')->whereIn(
                    'grade_id',
                    Grade::query()->select('id')->where('school_id', $schoolId),
                ),
            ],
            'category' => ['required', Rule::enum(HonorCategory::class)],
            'reason' => ['required', 'string', 'min:3', 'max:300'],
            'publish' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_array($this->input('student_ids'))) {
            // تكرار الطالب في الدفعة خطأ إدخال لا نيّة تكريم مزدوج.
            $this->merge(['student_ids' => array_values(array_unique($this->input('student_ids')))]);
        }
    }
}
