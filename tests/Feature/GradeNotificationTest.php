<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\AssessmentType;
use App\Models\Grade;
use App\Models\GradeScore;
use App\Models\Guardian;
use App\Models\Notification;
use App\Models\School;
use App\Models\SchoolNotificationSetting;
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
 * نشر العلامات ومن يصله ماذا.
 */
class GradeNotificationTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private Section $section;

    private Subject $subject;

    private Assessment $assessment;

    private Student $child;

    private Student $classmate;

    private User $admin;

    private User $teacher;

    private User $supervisor;

    private User $guardianUser;

    private User $otherGuardianUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $year = AcademicYear::factory()->current()->create(['school_id' => $this->school->id]);
        $term = Term::factory()->create(['academic_year_id' => $year->id]);
        $grade = Grade::factory()->create(['school_id' => $this->school->id]);
        $this->section = Section::factory()->create([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
        ]);
        $this->subject = Subject::factory()->create([
            'grade_id' => $grade->id,
            'term_id' => $term->id,
            'max_score' => 20,
            'pass_score' => 10,
        ]);

        $type = AssessmentType::factory()->create(['school_id' => $this->school->id]);
        $this->assessment = Assessment::factory()->create([
            'subject_id' => $this->subject->id,
            'assessment_type_id' => $type->id,
            'name' => 'امتحان الفصل الأول',
            'max_score' => 20,
        ]);

        $this->child = Student::factory()->create(['school_id' => $this->school->id]);
        $this->classmate = Student::factory()->create(['school_id' => $this->school->id]);

        foreach ([$this->child, $this->classmate] as $student) {
            StudentEnrollment::factory()->create([
                'student_id' => $student->id,
                'section_id' => $this->section->id,
                'academic_year_id' => $year->id,
            ]);
        }

        $this->admin = User::factory()->role(UserRole::Admin)->create(['school_id' => $this->school->id]);
        $this->teacher = User::factory()->role(UserRole::Teacher)->create(['school_id' => $this->school->id]);
        TeacherAssignment::factory()->create([
            'staff_id' => $this->teacher->id,
            'subject_id' => $this->subject->id,
            'section_id' => $this->section->id,
        ]);

        $this->supervisor = User::factory()->role(UserRole::Teacher)->create(['school_id' => $this->school->id]);
        $this->supervisor->supervisedSections()->attach($this->section->id);

        $this->guardianUser = $this->linkGuardian($this->child);
        $this->otherGuardianUser = $this->linkGuardian($this->classmate);
    }

    private function linkGuardian(Student $student): User
    {
        $user = User::factory()->role(UserRole::Guardian)->create(['school_id' => $this->school->id]);
        $guardian = Guardian::factory()->create(['school_id' => $this->school->id, 'user_id' => $user->id]);
        StudentGuardian::factory()->create(['student_id' => $student->id, 'guardian_id' => $guardian->id]);

        return $user;
    }

    private function enterScores(): void
    {
        GradeScore::factory()->create([
            'assessment_id' => $this->assessment->id,
            'student_id' => $this->child->id,
            'score' => 18,
        ]);
        GradeScore::factory()->create([
            'assessment_id' => $this->assessment->id,
            'student_id' => $this->classmate->id,
            'score' => 6,
        ]);
    }

    public function test_saving_scores_notifies_nobody(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson("/api/assessments/{$this->assessment->id}/scores", [
            'scores' => [
                ['student_id' => $this->child->id, 'score' => 18],
            ],
        ])->assertOk();

        // الشبكة قيد العمل: نصف الصف محفوظ وقد تُصحَّح العلامة غداً.
        $this->assertSame(0, Notification::where('type', 'grade_published')->count());
    }

    public function test_publishing_sends_each_guardian_their_own_child_score_only(): void
    {
        $this->enterScores();
        Sanctum::actingAs($this->teacher);

        $this->postJson("/api/assessments/{$this->assessment->id}/publish")->assertOk();

        $mine = Notification::where('user_id', $this->guardianUser->id)
            ->where('type', 'grade_published')
            ->first();

        $this->assertNotNull($mine);
        $this->assertStringContainsString($this->child->first_name, $mine->title);
        $this->assertStringContainsString('18', $mine->body);
        $this->assertStringContainsString('20', $mine->body);

        // وليّ الأمر الآخر لا يرى علامة 18 بحال.
        $theirs = Notification::where('user_id', $this->otherGuardianUser->id)
            ->where('type', 'grade_published')
            ->first();

        $this->assertNotNull($theirs);
        $this->assertStringNotContainsString('18', $theirs->body);
        $this->assertStringContainsString('6', $theirs->body);
    }

    public function test_supervisors_and_admins_get_the_section_sheet(): void
    {
        $this->enterScores();
        Sanctum::actingAs($this->teacher);

        $this->postJson("/api/assessments/{$this->assessment->id}/publish")->assertOk();

        foreach ([$this->supervisor, $this->admin] as $staff) {
            $summary = Notification::where('user_id', $staff->id)
                ->where('type', 'grade_summary')
                ->first();

            $this->assertNotNull($summary, "no sheet for user {$staff->id}");
            // صُحِّح 2، المعدّل 12، والأعلى 18.
            $this->assertStringContainsString('12', $summary->body);
            // ومن هو تحت علامة النجاح بالاسم.
            $this->assertStringContainsString($this->classmate->full_name, $summary->body);
        }
    }

    public function test_the_publishing_teacher_gets_a_confirmation(): void
    {
        $this->enterScores();
        Sanctum::actingAs($this->teacher);

        $this->postJson("/api/assessments/{$this->assessment->id}/publish")->assertOk();

        $this->assertTrue(
            Notification::where('user_id', $this->teacher->id)
                ->where('type', 'grade_summary')
                ->exists(),
        );
    }

    public function test_grades_cannot_be_published_twice(): void
    {
        $this->enterScores();
        Sanctum::actingAs($this->teacher);

        $this->postJson("/api/assessments/{$this->assessment->id}/publish")->assertOk();
        $before = Notification::count();

        $this->postJson("/api/assessments/{$this->assessment->id}/publish")->assertStatus(422);

        $this->assertSame($before, Notification::count());
    }

    public function test_an_empty_assessment_cannot_be_published(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson("/api/assessments/{$this->assessment->id}/publish")->assertStatus(422);
        $this->assertNull($this->assessment->fresh()->published_at);
    }

    public function test_a_teacher_of_another_subject_cannot_publish(): void
    {
        $this->enterScores();
        $outsider = User::factory()->role(UserRole::Teacher)->create(['school_id' => $this->school->id]);
        Sanctum::actingAs($outsider);

        $this->postJson("/api/assessments/{$this->assessment->id}/publish")->assertForbidden();
    }

    public function test_creating_an_assessment_tells_the_families_it_is_coming(): void
    {
        Sanctum::actingAs($this->admin);

        $type = AssessmentType::factory()->create(['school_id' => $this->school->id]);

        $this->postJson('/api/assessments', [
            'subject_id' => $this->subject->id,
            'assessment_type_id' => $type->id,
            'name' => 'كويز الوحدة الثانية',
            'held_on' => now()->addWeek()->toDateString(),
            'max_score' => 10,
        ])->assertCreated();

        $this->assertTrue(
            Notification::where('user_id', $this->guardianUser->id)
                ->where('type', 'assessment_created')
                ->exists(),
        );
    }

    public function test_a_school_switch_silences_the_key_for_everyone(): void
    {
        $this->enterScores();

        SchoolNotificationSetting::create([
            'school_id' => $this->school->id,
            'app' => 'guardian',
            'key' => 'grade_published',
            'group' => 'academic',
            'is_enabled' => false,
        ]);

        Sanctum::actingAs($this->teacher);
        $this->postJson("/api/assessments/{$this->assessment->id}/publish")->assertOk();

        $this->assertSame(0, Notification::where('type', 'grade_published')->count());
        // وكشف الكادر يبقى يعمل: المفتاحان مستقلان.
        $this->assertTrue(Notification::where('type', 'grade_summary')->exists());
    }
}
