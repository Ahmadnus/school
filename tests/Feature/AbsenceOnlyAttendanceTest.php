<?php

namespace Tests\Feature;

use App\Enums\AttendanceSessionStatus;
use App\Enums\UserRole;
use App\Models\AcademicYear;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Grade;
use App\Models\Notification;
use App\Models\School;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Services\StudentAttendanceSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * الغياب يُكتب، والحضور يُستدلّ عليه.
 *
 * تسجيل الحضور كان يكتب صفّاً لكل طالب كل يوم — أربعةً وخمسين ألف صفّ في
 * السنة لمدرسة من ثلاثمئة طالب، خمسةٌ وتسعون بالمئة منها بلا معلومة، ومثلها
 * إشعارات تقول لوليّ الأمر «ابنك حضر».
 *
 * والشرط الذي يجعل الاستدلال سليماً: أن يُعرف أن الكشف أُخذ أصلاً. فالجلسة
 * المسلَّمة هي مرجع «اليوم محسوب»، وبدونها لا يُفرَّق بين حاضرٍ ويومٍ لم
 * يُسجَّل فيه أحد — والفرق بينهما نسبةُ حضورٍ تُعلَّق على طالب.
 */
class AbsenceOnlyAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private Section $section;

    private Student $present;

    private Student $absent;

    protected function setUp(): void
    {
        parent::setUp();

        $school = School::factory()->create();
        $year = AcademicYear::factory()->current()->create(['school_id' => $school->id]);
        $grade = Grade::factory()->create(['school_id' => $school->id]);
        $this->section = Section::factory()->create([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
        ]);

        foreach (['present', 'absent'] as $role) {
            $student = Student::factory()->create(['school_id' => $school->id]);
            StudentEnrollment::factory()->create([
                'student_id' => $student->id,
                'section_id' => $this->section->id,
                'academic_year_id' => $year->id,
            ]);
            $this->{$role} = $student;
        }

        Sanctum::actingAs(
            User::factory()->role(UserRole::Admin)->create(['school_id' => $school->id]),
        );
    }

    private function submit(string $date): void
    {
        $this->postJson("/api/sections/{$this->section->id}/attendance", [
            'date' => $date,
            'records' => [
                ['student_id' => $this->present->id, 'status' => 'present'],
                ['student_id' => $this->absent->id, 'status' => 'absent'],
            ],
            'submit' => true,
        ])->assertSuccessful();
    }

    public function test_only_the_absence_is_written(): void
    {
        $this->submit('2026-09-01');

        $this->assertSame(1, AttendanceRecord::count());
        $this->assertSame(
            $this->absent->id,
            AttendanceRecord::firstOrFail()->student_id,
        );
    }

    public function test_the_present_student_is_counted_from_the_session(): void
    {
        $this->submit('2026-09-01');
        $this->submit('2026-09-02');

        $summary = StudentAttendanceSummary::for($this->present);

        $this->assertSame(2, $summary['present']);
        $this->assertSame(0, $summary['absent']);
        $this->assertSame(2, $summary['recorded']);
        $this->assertSame(100.0, $summary['attendance_rate']);
    }

    public function test_the_absent_student_adds_up_to_the_same_days(): void
    {
        $this->submit('2026-09-01');
        $this->submit('2026-09-02');

        $summary = StudentAttendanceSummary::for($this->absent);

        // يومان محسوبان: غيابان وصفرُ حضور — المجموع لا يختلّ.
        $this->assertSame(0, $summary['present']);
        $this->assertSame(2, $summary['absent']);
        $this->assertSame(2, $summary['recorded']);
        $this->assertSame(0.0, $summary['attendance_rate']);
    }

    public function test_a_day_never_taken_counts_for_nobody(): void
    {
        // لا جلسة ولا سجلّ: النسبة `null` لا مئة بالمئة. ادّعاء الحضور في
        // يومٍ لم يُسجَّل فيه أحد أسوأ من الاعتراف بالجهل.
        $summary = StudentAttendanceSummary::for($this->present);

        $this->assertSame(0, $summary['recorded']);
        $this->assertNull($summary['attendance_rate']);
    }

    public function test_a_draft_sheet_is_not_counted_yet(): void
    {
        AttendanceSession::factory()->create([
            'section_id' => $this->section->id,
            'date' => '2026-09-01',
            'status' => AttendanceSessionStatus::Draft,
        ]);

        // كشفٌ لم يُسلَّم بعد قد يتغيّر؛ احتسابه يعطي رقماً ثم ينقضه.
        $this->assertSame(0, StudentAttendanceSummary::for($this->present)['recorded']);
    }

    public function test_correcting_an_absence_to_present_removes_the_mark(): void
    {
        $this->submit('2026-09-01');
        $this->assertSame(1, AttendanceRecord::count());

        // المعلّم صحّح: الطالب كان حاضراً. الوسم يجب أن يزول لا أن يُكتب فوقه.
        $this->postJson("/api/sections/{$this->section->id}/attendance", [
            'date' => '2026-09-01',
            'records' => [['student_id' => $this->absent->id, 'status' => 'present']],
            'submit' => true,
        ])->assertSuccessful();

        $this->assertSame(0, AttendanceRecord::count());
        $this->assertSame(1, StudentAttendanceSummary::for($this->absent)['present']);
    }

    public function test_families_hear_about_absence_not_about_presence(): void
    {
        $this->submit('2026-09-01');

        // إشعار «ابنك حضر» يوميّاً يُدرّب وليّ الأمر على تجاهل إشعارات
        // المدرسة، فيفوته إشعار الغياب حين يأتي.
        $this->assertSame(
            0,
            Notification::query()->where('type', 'attendance_present')->count(),
        );
    }
}
