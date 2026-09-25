<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\School;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * كشف كل يوم قائم بذاته، والكشف المُسلَّم يقول عن نفسه.
 *
 * الأوّل يمنع أن تُورَّث غيابات الأمس إلى اليوم — فيُعلَّق على طالب غيابٌ
 * لم يقع. والثاني يمنع أن يفتح المعلّم الشاشة فلا يعرف أسجّل اليوم أم لا،
 * فيسجّل ثانيةً.
 */
class AttendanceSheetPerDayTest extends TestCase
{
    use RefreshDatabase;

    private Section $section;

    private Student $absent;

    private Student $present;

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

        foreach (['absent', 'present'] as $role) {
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
                ['student_id' => $this->absent->id, 'status' => 'absent'],
                ['student_id' => $this->present->id, 'status' => 'present'],
            ],
            'submit' => true,
        ])->assertSuccessful();
    }

    /** @return array<string, mixed> */
    private function sheet(string $date): array
    {
        return $this->getJson("/api/sections/{$this->section->id}/attendance?date={$date}")
            ->assertOk()
            ->json('data');
    }

    public function test_yesterday_marks_do_not_appear_today(): void
    {
        $this->submit('2026-09-25');

        $today = $this->sheet('2026-09-26');
        $marked = array_filter(
            $today['rows'],
            fn (array $row) => $row['record'] !== null,
        );

        // يومٌ جديد يبدأ نظيفاً: غياب الأمس يخصّ الأمس.
        $this->assertSame([], array_values($marked));
        $this->assertNull($today['session']);
    }

    public function test_the_day_that_was_taken_keeps_its_marks(): void
    {
        $this->submit('2026-09-25');

        $sheet = $this->sheet('2026-09-25');
        $marked = array_values(array_filter(
            $sheet['rows'],
            fn (array $row) => $row['record'] !== null,
        ));

        $this->assertCount(1, $marked);
        $this->assertSame($this->absent->id, $marked[0]['student']['id']);
    }

    public function test_a_submitted_sheet_says_so(): void
    {
        $this->assertNull($this->sheet('2026-09-25')['session']);

        $this->submit('2026-09-25');

        // هذا ما تقرؤه الشاشة لتقول «كشف اليوم مُسلَّم» بدل أن يسجّله
        // المعلّم مرّتين.
        $this->assertSame('submitted', $this->sheet('2026-09-25')['session']['status']);
    }

    public function test_resubmitting_corrects_instead_of_duplicating(): void
    {
        $this->submit('2026-09-25');

        // التصحيح: من وُسم غائباً صار حاضراً.
        $this->postJson("/api/sections/{$this->section->id}/attendance", [
            'date' => '2026-09-25',
            'records' => [
                ['student_id' => $this->absent->id, 'status' => 'present'],
            ],
            'submit' => true,
        ])->assertSuccessful();

        $sheet = $this->sheet('2026-09-25');
        $marked = array_filter(
            $sheet['rows'],
            fn (array $row) => $row['record'] !== null,
        );

        $this->assertSame([], array_values($marked));
        $this->assertSame(2, $sheet['summary']['present']);
    }
}
