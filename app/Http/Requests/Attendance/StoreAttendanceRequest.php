<?php

namespace App\Http\Requests\Attendance;

use App\Enums\AttendanceStatus;
use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Roll call for one section on one day. Partial submission is allowed
 * (the screen shows "3/11"), so only the rows sent are written.
 */
class StoreAttendanceRequest extends FormRequest
{
    /**
     * الصلاحية تُفحَص **قبل** التحقّق من البيانات.
     *
     * لارافيل ينادي `authorize()` قبل `rules()`، وهذا مقصود هنا: بلا هذه
     * الدالّة كان فحص «الطالب في هذه الشعبة؟» يسبق فحص الصلاحية، فيتعلّم
     * معلّمٌ يجرّب شعبةً لا يدرّسها **هل الطالب مسجَّل فيها** من رسالة الخطأ،
     * قبل أن يُقال له إنه غير مُصرَّح له. القاعدة نفسها تبقى في السياسة؛
     * المتغيّر متى تُسأل.
     */
    public function authorize(): bool
    {
        $section = $this->route('section');

        return $section !== null
            && $this->user()?->can('takeAttendance', $section) === true;
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date'],
            // اختياريّ، لكنّه إن أُرسل فُحص: الشاشة تختار الصفّ ثمّ الشعبة،
            // والخادم يحرس القيد نفسه بدل أن يثق بالترتيب في الواجهة.
            'grade_id' => ['nullable', 'integer'],
            'records' => ['required', 'array', 'min:1'],
            'records.*.student_id' => ['required', 'distinct', 'integer'],
            'records.*.status' => ['required', Rule::enum(AttendanceStatus::class)],
            'submit' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $section = $this->route('section');

            SectionBelongsToGrade::check($validator, $section, $this->input('grade_id'));

            $ids = collect($this->input('records', []))->pluck('student_id')->filter()->all();

            if ($ids === []) {
                return;
            }

            // Attendance belongs to the section's own roster in the running year.
            $roster = Student::query()
                ->whereIn('id', $ids)
                ->inSection($section->id)
                ->pluck('id')
                ->all();

            foreach ($this->input('records', []) as $index => $row) {
                if (! in_array((int) $row['student_id'], $roster, true)) {
                    $validator->errors()->add(
                        "records.$index.student_id",
                        __('messages.attendance.not_in_section'),
                    );
                }
            }
        });
    }
}
