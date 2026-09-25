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

class AttendanceDailyResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_day_starts_with_a_clean_sheet(): void
    {
        $school = School::factory()->create();
        $year = AcademicYear::factory()->current()->create(['school_id' => $school->id]);
        $grade = Grade::factory()->create(['school_id' => $school->id]);
        $section = Section::factory()->create(['grade_id' => $grade->id, 'academic_year_id' => $year->id]);
        $student = Student::factory()->create(['school_id' => $school->id]);
        StudentEnrollment::factory()->create([
            'student_id' => $student->id,
            'section_id' => $section->id,
            'academic_year_id' => $year->id,
        ]);
        Sanctum::actingAs(User::factory()->role(UserRole::Admin)->create(['school_id' => $school->id]));

        $this->postJson("/api/sections/{$section->id}/attendance", [
            'date' => '2026-09-01',
            'records' => [['student_id' => $student->id, 'status' => 'absent']],
            'submit' => true,
        ])->assertSuccessful();

        $old = $this->getJson("/api/sections/{$section->id}/attendance?date=2026-09-01")->json('data');
        $this->assertSame('absent', $old['rows'][0]['record']['status']);

        $new = $this->getJson("/api/sections/{$section->id}/attendance?date=2026-09-03")->json('data');
        $this->assertNull($new['rows'][0]['record']);
        $this->assertSame(1, $new['summary']['present']);
    }
}
