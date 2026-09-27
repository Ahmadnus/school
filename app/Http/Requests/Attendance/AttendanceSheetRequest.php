<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * قراءة كشف الحضور: التاريخ، والصفّ الذي يجب أن تكون الشعبة منه.
 *
 * `grade_id` اختياريّ لأنّ الشعبة تكفي لتعيين الكشف. لكنّه إن أُرسل **فُحص**:
 * الشاشة تختار الصفّ أوّلاً ثمّ الشعبة، والخادم يجب أن يحرس القيد نفسه.
 * واجهةٌ تحرس ترتيباً والخادم لا يحرسه واجهةٌ تُلتفّ بنداءٍ مباشر.
 */
class AttendanceSheetRequest extends FormRequest
{
    /**
     * الصلاحية تُفحَص **قبل** التحقّق من البيانات.
     *
     * لارافيل ينادي `authorize()` قبل `rules()`، وهذا مقصود: بلا هذه الدالّة
     * يسبق فحصُ البيانات فحصَ الصلاحية، فتُفشي رسالة الخطأ ما لا يحقّ للسائل.
     * القاعدة نفسها تبقى في السياسة؛ المتغيّر **متى** تُسأل.
     *
     * و`view` هي صلاحية القراءة كما كانت قبل هذا العمل — الحفظ وحده على
     * `takeAttendance` (انظر `StoreAttendanceRequest`).
     */
    public function authorize(): bool
    {
        $section = $this->route('section');

        return $section !== null
            && $this->user()?->can('view', $section) === true;
    }

    public function rules(): array
    {
        return [
            'date' => ['nullable', 'date'],
            'grade_id' => ['nullable', 'integer'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            SectionBelongsToGrade::check($validator, $this->route('section'), $this->input('grade_id'));
        });
    }
}
