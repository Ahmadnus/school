<?php

namespace App\Services;

use App\Enums\AttendanceSessionStatus;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The attendance summary shown at the top of a student's profile.
 *
 * الحضور يُكتب سجلّاً منذ ٢٠٢٦-١٠-٠٥. قبلها كانت الغيابات والتأخّرات وحدها
 * تُكتب، ويُستدلّ على الحضور بالطرح. فالحاضر هنا = سجلّات «حاضر» + أيّامٌ
 * سُلِّم فيها كشف الشعبة ولا سجلّ فيها للطالب (أيّام ما قبل التغيير). ولا
 * يُعدّ يومٌ مرّتين: يومٌ له سجلّ لا يدخل في الاستدلال.
 *
 * ويوم بلا جلسةٍ مسلَّمة ولا سجلّ لا يُحسب لأحد: «لم يُؤخذ الحضور» ليس
 * «حاضراً» — والفرق بينهما نسبةُ حضورٍ تُعلَّق على طالب.
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
        $sessionDays = AttendanceSession::query()
            ->where('status', AttendanceSessionStatus::Submitted)
            ->whereIn('section_id', $student->enrollments()->pluck('section_id'))
            ->when($constrain !== null, $constrain ?? fn () => null)
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->unique();
        $recordDays = $records->map(fn ($r) => Carbon::parse($r->date)->toDateString())->unique();

        $late = $base['late'];
        $absent = $base['excused'] + $base['unexcused'];

        // حاضرٌ مكتوب + حاضرٌ مستدلّ عليه من يومٍ مسلَّم بلا سجلّ.
        $present = $base['present'] + $sessionDays->diff($recordDays)->count();

        $recorded = $present + $late + $absent;
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
