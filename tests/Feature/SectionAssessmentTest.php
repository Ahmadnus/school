<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AcademicYear;
use App\Models\AssessmentType;
use App\Models\Grade;
use App\Models\Guardian;
use App\Models\Notification;
use App\Models\School;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\StudentGuardian;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * التقييم يُنشأ لشعبة: صفّ ← شعبة ← مادة. كشفه طلاب الشعبة وحدهم، وأهلها
 * وحدهم يُبلَغون، والعلامة لا تصل الأهل إلا بعد «نشر».
 */
class SectionAssessmentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Section $boys;

    private Section $girls;

    private Subject $subject;

    private AssessmentType $type;

    private Student $boy;

    private Student $girl;

    private User $boyGuardian;

    private User $girlGuardian;

    protected function setUp(): void
    {
        parent::setUp();

        $school = School::factory()->create();
        $year = AcademicYear::factory()->current()->create(['school_id' => $school->id]);
        $term = Term::factory()->create(['academic_year_id' => $year->id, 'is_current' => true]);
        $grade = Grade::factory()->create(['school_id' => $school->id]);
        $this->boys = Section::factory()->create(['grade_id' => $grade->id, 'academic_year_id' => $year->id, 'name' => 'ذكور']);
        $this->girls = Section::factory()->create(['grade_id' => $grade->id, 'academic_year_id' => $year->id, 'name' => 'إناث']);
        $this->subject = Subject::factory()->create(['grade_id' => $grade->id, 'term_id' => $term->id, 'name' => 'هندسة']);
        $this->type = AssessmentType::factory()->create(['school_id' => $school->id]);
        $this->admin = User::factory()->role(UserRole::SuperAdmin)->create(['school_id' => $school->id]);

        [$this->boy, $this->boyGuardian] = $this->enrol($school, $year, $this->boys);
        [$this->girl, $this->girlGuardian] = $this->enrol($school, $year, $this->girls);
    }

    /** @return array{Student, User} */
    private function enrol(School $school, AcademicYear $year, Section $section): array
    {
        $student = Student::factory()->create(['school_id' => $school->id]);
        StudentEnrollment::factory()->create([
            'student_id' => $student->id, 'section_id' => $section->id, 'academic_year_id' => $year->id,
        ]);
        $user = User::factory()->role(UserRole::Guardian)->create(['school_id' => $school->id]);
        $guardian = Guardian::factory()->create(['school_id' => $school->id, 'user_id' => $user->id]);
        StudentGuardian::factory()->create(['student_id' => $student->id, 'guardian_id' => $guardian->id]);

        return [$student, $user];
    }

    private function create(Section $section, string $name = 'مذاكرة أولى'): array
    {
        return $this->postJson('/api/assessments', [
            'subject_id' => $this->subject->id,
            'section_id' => $section->id,
            'assessment_type_id' => $this->type->id,
            'name' => $name,
            'max_score' => 20,
        ])->assertCreated()->json('data');
    }

    public function test_a_section_assessment_carries_the_section_and_its_name(): void
    {
        Sanctum::actingAs($this->admin);

        $boys = $this->create($this->boys);
        $girls = $this->create($this->girls);

        $this->assertSame('مذاكرة أولى — ذكور', $boys['name']);
        $this->assertSame('مذاكرة أولى — إناث', $girls['name']);
        $this->assertSame($this->boys->id, $boys['section_id']);
        $this->assertSame('ذكور', $boys['section_name']);
        $this->assertFalse($boys['is_published']);
    }

    public function test_only_the_sections_families_hear_of_it(): void
    {
        Sanctum::actingAs($this->admin);

        $this->create($this->boys);

        $this->assertSame(1, Notification::query()->where('user_id', $this->boyGuardian->id)->where('type', 'assessment_created')->count());
        $this->assertSame(0, Notification::query()->where('user_id', $this->girlGuardian->id)->where('type', 'assessment_created')->count());
    }

    public function test_the_sheet_lists_the_section_and_rejects_other_students(): void
    {
        Sanctum::actingAs($this->admin);
        $id = $this->create($this->boys)['id'];

        $rows = $this->getJson("/api/assessments/{$id}/scores")->assertOk()->json('data.rows');
        $this->assertEquals([$this->boy->id], array_column(array_column($rows, 'student'), 'id'));

        $this->postJson("/api/assessments/{$id}/scores", [
            'scores' => [['student_id' => $this->girl->id, 'score' => 10]],
        ])->assertStatus(422);
    }

    public function test_the_score_reaches_the_family_only_after_publishing(): void
    {
        Sanctum::actingAs($this->admin);
        $id = $this->create($this->boys)['id'];
        $this->postJson("/api/assessments/{$id}/scores", [
            'scores' => [['student_id' => $this->boy->id, 'score' => 17]],
        ])->assertOk();

        $seen = function () {
            Sanctum::actingAs($this->boyGuardian);
            $subjects = $this->getJson("/api/students/{$this->boy->id}/subjects")->assertOk()->json('data.subjects');
            Sanctum::actingAs($this->admin);

            return collect($subjects)->firstWhere('id', $this->subject->id)['assessments'];
        };

        $this->assertSame([], $seen());

        $this->postJson("/api/assessments/{$id}/publish")->assertOk()->assertJsonPath('data.is_published', true);

        $this->assertEquals(17, $seen()[0]['score']);
        $this->assertSame(1, Notification::query()->where('user_id', $this->boyGuardian->id)->where('type', 'grade_published')->count());
    }

    public function test_a_section_from_another_grade_is_rejected(): void
    {
        Sanctum::actingAs($this->admin);
        $foreign = Section::factory()->create(['academic_year_id' => $this->boys->academic_year_id]);

        $this->postJson('/api/assessments', [
            'subject_id' => $this->subject->id,
            'section_id' => $foreign->id,
            'assessment_type_id' => $this->type->id,
            'name' => 'x',
        ])->assertUnprocessable();
    }
}
