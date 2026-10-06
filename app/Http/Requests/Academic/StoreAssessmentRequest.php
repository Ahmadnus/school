<?php

namespace App\Http\Requests\Academic;

use App\Models\Grade;
use App\Models\Section;
use App\Models\Subject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssessmentRequest extends FormRequest
{
    public function rules(): array
    {
        $schoolId = $this->user()->school_id;

        return [
            'subject_id' => [
                'required',
                Rule::exists('subjects', 'id')->whereIn(
                    'grade_id',
                    Grade::query()->select('id')->where('school_id', $schoolId),
                ),
            ],
            'assessment_type_id' => [
                'required',
                Rule::exists('assessment_types', 'id')->where('school_id', $schoolId),
            ],
            // اختياريّ: تقييمٌ بلا شعبة تقييمُ الصفّ كلّه كما كان.
            'section_id' => [
                'nullable',
                Rule::exists('sections', 'id')->whereIn(
                    'grade_id',
                    Grade::query()->select('id')->where('school_id', $schoolId),
                ),
            ],
            'name' => ['required', 'string', 'max:150'],
            'max_score' => ['nullable', 'numeric', 'min:1', 'max:9999'],
            'weight_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    /**
     * تقييم الشعبة يحمل اسمها: «مذاكرة أولى — ذكور».
     *
     * الفهرس `unique(subject_id, name)` يبقى كما هو (انظر هجرة التسميع)، فلولا
     * هذا لما أمكن «مذاكرة أولى» لشعبتين في المادة نفسها. والاسم يُكمَل قبل
     * التحقّق ليفحص حارسُ التكرار الاسمَ الذي سيُحفظ فعلاً.
     */
    protected function prepareForValidation(): void
    {
        $section = $this->filled('section_id') ? Section::find($this->integer('section_id')) : null;
        $name = trim((string) $this->input('name'));

        if ($section && $name !== '' && ! str_ends_with($name, '— '.$section->name)) {
            $this->merge(['name' => $name.' — '.$section->name]);
        }
    }

    public function withValidator($validator): void
    {
        $validator->sometimes('name', [
            Rule::unique('assessments', 'name')->where('subject_id', $this->input('subject_id')),
        ], fn () => $this->filled('subject_id'));

        $validator->after(function ($validator) {
            if (! $this->filled('section_id') || ! $this->filled('subject_id')) {
                return;
            }

            $sectionGrade = Section::query()->whereKey($this->integer('section_id'))->value('grade_id');
            $subjectGrade = Subject::query()->whereKey($this->integer('subject_id'))->value('grade_id');

            if ($sectionGrade !== $subjectGrade) {
                $validator->errors()->add('section_id', __('messages.assessment.section_grade_mismatch'));
            }
        });
    }
}
