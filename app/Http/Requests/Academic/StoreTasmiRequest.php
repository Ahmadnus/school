<?php

namespace App\Http\Requests\Academic;

use App\Models\Grade;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * حفظ جلسة تسميع: الشعبة والمادة والتاريخ، ومن شارك بدرجته.
 *
 * `entries` قد تكون فارغة: أستاذٌ أزال كل من أشّرهم يُفرغ الجلسة، وهذا فعلٌ
 * مقصود لا خطأ إدخال.
 *
 * والتحقّق هنا يحرس ثلاثة أشياء يهدم غيابُها البيانات:
 *
 *  1. **الشعبة من صفّ المادة.** مادةُ الصفّ التاسع لا تُسمَّع لشعبةٍ من
 *     الحادي عشر، وأسماء الشعب متشابهة بين الصفوف.
 *  2. **الطالب في تلك الشعبة.** درجةٌ تُكتب لطالبٍ من شعبةٍ أخرى تظهر في
 *     علامة مادةٍ لا يدرسها.
 *  3. **الدرجة داخل حدّها.** ولا تُقبل سالبة.
 */
class StoreTasmiRequest extends FormRequest
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
            'section_id' => ['required', 'integer'],
            'held_on' => ['required', 'date'],
            'max_score' => ['nullable', 'numeric', 'min:1', 'max:1000'],
            'entries' => ['present', 'array', 'max:300'],
            'entries.*.student_id' => ['required', 'distinct', 'integer'],
            // العلامة إلزامية لكل مشارك: من لا درجة له ليس مشاركاً، ولا
            // يُرسَل أصلاً. صفٌّ بعلامة فارغة يجعل التسميع يبدو ناقصاً وهو تامّ.
            'entries.*.score' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $subject = Subject::query()->find($this->input('subject_id'));
            $section = Section::query()->find($this->input('section_id'));

            if (! $subject || ! $section) {
                if (! $section) {
                    $validator->errors()->add('section_id', __('messages.tasmi.section_missing'));
                }

                return;
            }

            // 1. الشعبة من صفّ المادة.
            if ($section->grade_id !== $subject->grade_id) {
                $validator->errors()->add('section_id', __('messages.tasmi.section_not_in_grade'));

                return;
            }

            $max = (float) ($this->input('max_score') ?? \App\Services\TasmiSession::DEFAULT_MAX);

            // 3. الدرجة داخل حدّها — يُفحَص هنا لأن الحدّ يأتي في الطلب نفسه.
            foreach ($this->input('entries', []) as $index => $entry) {
                if (isset($entry['score']) && (float) $entry['score'] > $max) {
                    $validator->errors()->add(
                        "entries.$index.score",
                        __('messages.tasmi.score_above_max', ['max' => $max]),
                    );
                }
            }

            $ids = collect($this->input('entries', []))->pluck('student_id')->filter()->all();

            if ($ids === []) {
                return;
            }

            // 2. الطالب في تلك الشعبة، في السنة الجارية.
            $roster = Student::query()
                ->whereIn('id', $ids)
                ->inSection($section->id)
                ->pluck('id')
                ->all();

            foreach ($this->input('entries', []) as $index => $entry) {
                if (! in_array((int) $entry['student_id'], $roster, true)) {
                    $validator->errors()->add(
                        "entries.$index.student_id",
                        __('messages.tasmi.student_not_in_section'),
                    );
                }
            }
        });
    }
}
