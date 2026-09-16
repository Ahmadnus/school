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

class BulkEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_reports_plan_and_store_applies_it_without_overwriting(): void
    {
        $school = School::factory()->create();
        $year = AcademicYear::factory()->current()->create(['school_id' => $school->id]);
        $grade = Grade::factory()->create(['school_id' => $school->id]);
        $target = Section::factory()->create(['grade_id' => $grade->id, 'academic_year_id' => $year->id, 'capacity' => 3]);
        $other = Section::factory()->create(['grade_id' => $grade->id, 'academic_year_id' => $year->id]);

        $students = Student::factory()->count(3)->create(['school_id' => $school->id]);
        // One is already enrolled elsewhere this year — must be skipped, never moved.
        StudentEnrollment::factory()->create([
            'student_id' => $students[0]->id,
            'section_id' => $other->id,
            'academic_year_id' => $year->id,
        ]);

        $admin = User::factory()->role(UserRole::Admin)->create(['school_id' => $school->id]);
        Sanctum::actingAs($admin);

        $body = ['student_ids' => $students->pluck('id')->all(), 'section_id' => $target->id];

        $this->postJson('/api/enrollments/bulk/preview', $body)
            ->assertOk()
            ->assertJsonCount(2, 'data.to_enroll')
            ->assertJsonCount(1, 'data.skipped')
            ->assertJsonPath('data.over_capacity', false);

        $this->assertDatabaseCount('student_enrollments', 1);

        $this->postJson('/api/enrollments/bulk', $body)->assertCreated();

        $this->assertDatabaseCount('student_enrollments', 3);
        $this->assertDatabaseHas('student_enrollments', ['student_id' => $students[0]->id, 'section_id' => $other->id]);

        // Capacity is enforced on apply.
        $extra = Student::factory()->count(2)->create(['school_id' => $school->id]);
        $this->postJson('/api/enrollments/bulk', ['student_ids' => $extra->pluck('id')->all(), 'section_id' => $target->id])
            ->assertStatus(422)
            ->assertJsonPath('data.over_capacity', true);
    }

    public function test_teachers_and_guardians_cannot_bulk_enroll(): void
    {
        $school = School::factory()->create();
        $year = AcademicYear::factory()->current()->create(['school_id' => $school->id]);
        $grade = Grade::factory()->create(['school_id' => $school->id]);
        $section = Section::factory()->create(['grade_id' => $grade->id, 'academic_year_id' => $year->id]);
        $student = Student::factory()->create(['school_id' => $school->id]);

        foreach ([UserRole::Teacher, UserRole::Guardian] as $role) {
            Sanctum::actingAs(User::factory()->role($role)->create(['school_id' => $school->id]));
            $this->postJson('/api/enrollments/bulk', ['student_ids' => [$student->id], 'section_id' => $section->id])
                ->assertForbidden();
        }
    }
}
