<?php

namespace App\Http\Requests\Attendance;

use App\Models\Section;
use Illuminate\Contracts\Validation\Validator;

/**
 * «هذه الشعبة من هذا الصفّ» — الفحص الذي يمنع تسجيل غيابٍ لشعبةٍ خطأ.
 *
 * أسماء الشعب تتشابه بين الصفوف: «أ» في التاسع و«أ» في البكالوريا. فشاشة
 * الحضور تختار الصفّ أوّلاً ثمّ الشعبة، ولا تعرض شعب الصفوف الأخرى. لكن
 * الترتيب في الواجهة وحده ليس حراسة: من نادى الـAPI مباشرةً بصفٍّ وشعبةٍ من
 * غيره يمرّ. وثمن الخطأ ليس رسالةً على الشاشة، بل إشعارُ غيابٍ يصل أباً عن
 * ابنٍ كان حاضراً في شعبةٍ أخرى.
 *
 * مشتركة بين قراءة الكشف وحفظه: قاعدةٌ واحدة في مكان واحد.
 */
final class SectionBelongsToGrade
{
    public static function check(Validator $validator, ?Section $section, mixed $gradeId): void
    {
        if ($section === null || $gradeId === null || $gradeId === '') {
            return;
        }

        if ((int) $section->grade_id !== (int) $gradeId) {
            $validator->errors()->add(
                'grade_id',
                __('messages.attendance.section_not_in_grade'),
            );
        }
    }
}
