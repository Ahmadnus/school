<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\AssessmentType;
use App\Models\Grade;
use App\Models\GradeScore;
use App\Models\Guardian;
use App\Models\PostType;
use App\Models\School;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\StudentGuardian;
use App\Models\Subject;
use App\Models\TeacherAssignment;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * كلٌّ يصل إلى ما يخصّه — وُجدت هذه الثغرات في فحص ما قبل التسليم:
 *
 * - وليّ الأمر كان يقرأ ملفّ أيّ وليّ أمر (هاتفه وأولاده)، وقائمة طلاب أيّ
 *   صفّ وشعبة، وكشف حضورها، وعلامات أولاده قبل نشرها.
 * - الأستاذ كان يصل إلى طلاب المعهد كلّه: يقرأ ملفّاتهم، ويكتب علاماتهم
 *   وسلوكهم وملاحظاتهم، ويوجّه إليهم منشورات — ولو لم يدرّس شعبتهم.
 *
 * والمشرف العام يبقى بصلاحية كاملة على كلّ شيء.
 */
class ReachScopedAccessTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private Grade $grade;

    private Section $mine;

    private Section $other;

    private Student $child;

    private Student $classmate;

    private Student $stranger;

    private Guardian $otherGuardian;

    private User $guardianUser;

    private User $teacher;

    private User $superAdmin;

    private Subject $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $year = AcademicYear::factory()->current()->create(['school_id' => $this->school->id]);
        $term = Term::factory()->create(['academic_year_id' => $year->id, 'is_current' => true]);
        $this->grade = Grade::factory()->create(['school_id' => $this->school->id]);
        $this->mine = Section::factory()->create(['grade_id' => $this->grade->id, 'academic_year_id' => $year->id]);
        $this->other = Section::factory()->create(['grade_id' => $this->grade->id, 'academic_year_id' => $year->id]);
        $this->subject = Subject::factory()->create(['grade_id' => $this->grade->id, 'term_id' => $term->id]);

        $this->child = Student::factory()->create(['school_id' => $this->school->id]);
        $this->classmate = Student::factory()->create(['school_id' => $this->school->id]);
        $this->stranger = Student::factory()->create(['school_id' => $this->school->id]);

        foreach ([[$this->child, $this->other], [$this->classmate, $this->other], [$this->stranger, $this->other]] as [$student, $section]) {
            StudentEnrollment::factory()->create([
                'student_id' => $student->id,
                'section_id' => $section->id,
                'academic_year_id' => $year->id,
            ]);
        }

        $this->guardianUser = User::factory()->role(UserRole::Guardian)->create(['school_id' => $this->school->id]);
        $guardian = Guardian::factory()->create(['school_id' => $this->school->id, 'user_id' => $this->guardianUser->id]);
        StudentGuardian::factory()->create(['student_id' => $this->child->id, 'guardian_id' => $guardian->id]);

        $this->otherGuardian = Guardian::factory()->create(['school_id' => $this->school->id]);
        StudentGuardian::factory()->create(['student_id' => $this->classmate->id, 'guardian_id' => $this->otherGuardian->id]);

        // الأستاذ يدرّس المادة في شعبته وحدها؛ الطلاب الثلاثة في الشعبة الأخرى.
        $this->teacher = User::factory()->role(UserRole::Teacher)->create(['school_id' => $this->school->id]);
        TeacherAssignment::factory()->create([
            'staff_id' => $this->teacher->id,
            'subject_id' => $this->subject->id,
            'section_id' => $this->mine->id,
        ]);

        $this->superAdmin = User::factory()->role(UserRole::SuperAdmin)->create(['school_id' => $this->school->id]);
    }

    // ------------------------------------------------------------ guardian

    public function test_a_guardian_cannot_read_another_familys_guardian_file(): void
    {
        Sanctum::actingAs($this->guardianUser);

        $this->getJson("/api/guardians/{$this->otherGuardian->id}")->assertForbidden();
    }

    public function test_a_guardian_cannot_list_classmates_or_read_the_attendance_sheet(): void
    {
        Sanctum::actingAs($this->guardianUser);

        $this->getJson("/api/sections/{$this->other->id}/students")->assertForbidden();
        $this->getJson("/api/sections/{$this->other->id}/attendance")->assertForbidden();

        // قائمة الصفّ لا تحمل إلّا أولاده.
        $ids = collect($this->getJson("/api/grades/{$this->grade->id}/students")->assertOk()->json('data'))->pluck('id');
        $this->assertEquals([$this->child->id], $ids->all());
    }

    public function test_a_guardian_sees_a_score_only_after_it_is_published(): void
    {
        $assessment = Assessment::factory()->create(['subject_id' => $this->subject->id, 'max_score' => 20]);
        GradeScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $this->child->id, 'score' => 15]);

        Sanctum::actingAs($this->guardianUser);
        $scores = fn () => collect($this->getJson("/api/students/{$this->child->id}/subjects")->assertOk()->json('data.subjects'))
            ->firstWhere('id', $this->subject->id)['assessments'];

        $this->assertSame([], $scores());
        $this->getJson("/api/assessments/{$assessment->id}/scores")->assertForbidden();

        $assessment->forceFill(['published_at' => now()])->save();

        $this->assertEquals(15, $scores()[0]['score']);
    }

    public function test_tasmi_reaches_the_guardian_without_a_publish_step(): void
    {
        $type = AssessmentType::factory()->create(['school_id' => $this->school->id, 'name' => 'تسميع']);
        $assessment = Assessment::factory()->create(['subject_id' => $this->subject->id, 'assessment_type_id' => $type->id, 'max_score' => 10]);
        GradeScore::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $this->child->id, 'score' => 9]);

        Sanctum::actingAs($this->guardianUser);

        $assessments = collect($this->getJson("/api/students/{$this->child->id}/subjects")->json('data.subjects'))
            ->firstWhere('id', $this->subject->id)['assessments'];
        $this->assertEquals(9, $assessments[0]['score']);
    }

    // ------------------------------------------------------------- teacher

    public function test_a_teacher_lists_only_the_students_of_their_sections(): void
    {
        $mineStudent = Student::factory()->create(['school_id' => $this->school->id]);
        StudentEnrollment::factory()->create([
            'student_id' => $mineStudent->id,
            'section_id' => $this->mine->id,
            'academic_year_id' => $this->mine->academic_year_id,
        ]);

        Sanctum::actingAs($this->teacher);

        $ids = collect($this->getJson('/api/students')->assertOk()->json('data'))->pluck('id');
        $this->assertEquals([$mineStudent->id], $ids->all());

        $this->getJson("/api/students/{$this->stranger->id}")->assertForbidden();
        $this->getJson("/api/students/{$this->stranger->id}/profile")->assertForbidden();
        $this->getJson("/api/sections/{$this->other->id}/students")->assertForbidden();
        $this->getJson("/api/sections/{$this->mine->id}/students")->assertOk();
        $this->getJson("/api/guardians/{$this->otherGuardian->id}")->assertForbidden();
    }

    public function test_a_teacher_cannot_write_on_a_student_they_do_not_teach(): void
    {
        $assessment = Assessment::factory()->create(['subject_id' => $this->subject->id, 'max_score' => 20]);
        $postType = PostType::factory()->create(['school_id' => $this->school->id]);

        Sanctum::actingAs($this->teacher);

        $this->postJson("/api/assessments/{$assessment->id}/scores", [
            'scores' => [['student_id' => $this->stranger->id, 'score' => 1]],
        ])->assertForbidden();
        $this->assertDatabaseMissing('grades_scores', ['student_id' => $this->stranger->id]);

        $this->postJson("/api/students/{$this->stranger->id}/behavior", [
            'type' => 'negative', 'title' => 'x', 'occurred_on' => now()->toDateString(),
        ])->assertForbidden();
        $this->postJson("/api/students/{$this->stranger->id}/notes", ['body' => 'x'])->assertForbidden();

        $this->postJson('/api/posts', [
            'post_type_id' => $postType->id,
            'title' => 'x',
            'targets' => [['scope' => 'student', 'target_id' => $this->stranger->id]],
        ])->assertUnprocessable()->assertJsonValidationErrors('targets.0.target_id');
        $this->postJson('/api/posts', [
            'post_type_id' => $postType->id,
            'title' => 'x',
            'targets' => [['scope' => 'section', 'target_id' => $this->other->id]],
        ])->assertUnprocessable()->assertJsonValidationErrors('targets.0.target_id');

        // في شعبته يكتب كما كان.
        $this->postJson('/api/posts', [
            'post_type_id' => $postType->id,
            'title' => 'x',
            'targets' => [['scope' => 'section', 'target_id' => $this->mine->id]],
        ])->assertCreated();
    }

    // --------------------------------------------------------- super admin

    public function test_the_super_admin_keeps_full_access(): void
    {
        $assessment = Assessment::factory()->create(['subject_id' => $this->subject->id, 'max_score' => 20]);
        $postType = PostType::factory()->create(['school_id' => $this->school->id]);

        Sanctum::actingAs($this->superAdmin);

        $this->assertCount(3, $this->getJson('/api/students')->assertOk()->json('data'));
        $this->getJson("/api/guardians/{$this->otherGuardian->id}")->assertOk();
        $this->getJson("/api/sections/{$this->other->id}/students")->assertOk();
        $this->getJson("/api/sections/{$this->other->id}/attendance")->assertOk();
        $this->getJson("/api/students/{$this->stranger->id}/profile")->assertOk();
        $this->postJson("/api/assessments/{$assessment->id}/scores", [
            'scores' => [['student_id' => $this->stranger->id, 'score' => 12]],
        ])->assertOk();
        $this->postJson("/api/students/{$this->stranger->id}/notes", ['body' => 'x'])->assertCreated();
        $this->postJson('/api/posts', [
            'post_type_id' => $postType->id,
            'title' => 'x',
            'targets' => [['scope' => 'student', 'target_id' => $this->stranger->id]],
        ])->assertCreated();
    }
}
