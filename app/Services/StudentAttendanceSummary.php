<?php

namespace App\Services;

use App\Enums\AttendanceSessionStatus;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;

/**
 * The attendance summary shown at the top of a student's profile.
 *
 * الحضور لا يُخزَّن: تُكتب الغيابات والتأخّرات وحدها، ويُستدلّ على الحضور
 * بالطرح — يومٌ سُلِّم فيه كشف الشعبة ولم يُكتب فيه للطالب سجلّ هو يوم حضور.
 * وهذا ما يجعل أيام الحضور مجّانيةً في التخزين وفي الإشعارات معاً.
 *
 * ولهذا يُعدّ «المسجَّل» من الجلسات المسلَّمة لا من الصفوف: لولا ذلك لما
 * أمكن التفريق بين «حاضر» و«لم يُؤخذ الحضور أصلاً» — والفرق بينهما نسبةُ
 * حضورٍ تُعلَّق على طالب.
 *
 * "excused" is derived from an accepted excuse covering the day (decision
 * 4-a). Two rates are returned:
 *
 * - `attendance_rate`: (present + late) / recorded — the raw figure.
 * - `effective_rate`: (present + late) / (recorded − excused) — the rate the
 *   school actually judges by, where an excused day does not count against
 *   the student. Both are null when nothing has been recorded.
 */
class StudentAttendanceSummary
{
    /**
     * @param  (\Closure(Builder): mixed)|null  $constrain  Extra filters (date range, section…).
     * @return array{present:int, late:int, absent:int, excused:int, unexcused:int, recorded:int, attendance_rate:?float, effective_rate:?float}
     */
    public static function for(Student $student, ?\Closure $constrain = null): array
    {
        $query = AttendanceRecord::query()->where('student_id', $student->id);

        if ($constrain) {
            $constrain($query);
        }

        $records = $query->get(['id', 'student_id', 'date', 'status']);
        $base = AttendanceSummary::for($records);

        // الجلسات المسلَّمة لشُعَب الطالب، بالمرشّحات نفسها: الإغلاق يعمل
        // على `date` و`section_id` و`section.academic_year_id`، وثلاثتها
        // أعمدةٌ في الجلسة كما في السجلّ.
        $sessions = AttendanceSession::query()
            ->where('status', AttendanceSessionStatus::Submitted)
            ->whereIn('section_id', $student->enrollments()->pluck('section_id'))
            ->when($constrain !== null, $constrain ?? fn () => null)
            ->count();

        $late = $base['late'];
        $absent = $base['excused'] + $base['unexcused'];

        // `max` حارس لا تجميل: سجلٌّ في يومٍ لم تُسلَّم جلسته (إدخال يدويّ
        // قديم) كان سيُنتج حضوراً سالباً.
        $present = max(0, $sessions - $late - $absent);

        $recorded = max($sessions, $late + $absent);
        $attended = $present + $late;
        $judged = $recorded - $base['excused'];

        return [
            'present' => $present,
            'late' => $late,
            'absent' => $absent,
            'excused' => $base['excused'],
            'unexcused' => $base['unexcused'],
            'recorded' => $recorded,
            'attendance_rate' => $recorded > 0 ? round($attended / $recorded * 100, 1) : null,
            'effective_rate' => $judged > 0 ? round($attended / $judged * 100, 1) : null,
        ];
    }
}
