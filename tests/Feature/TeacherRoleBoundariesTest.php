<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\AssessmentType;
use App\Models\FeePlan;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\Guardian;
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
 * حدود دور المعلّم: يُدرّس ويُقيّم، ولا يُدير.
 *
 * الحدّ المهمّ هنا ليس ما يُمنع فحسب، بل أن يبقى ما يُسمح به سالماً: سياسة
 * تُغلق أكثر من اللازم تُعطّل المعلّم عن عمله فيُطلب له حساب إدارة — فيسقط
 * الحدّ كلّه من حيث أُريد له أن يُصان.
 */
class TeacherRoleBoundariesTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $teacher;

    private Section $section;

    private Subject $subject;

    private Student $student;

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
        ]);

        $this->student = Student::factory()->create(['school_id' => $this->school->id]);
        StudentEnrollment::factory()->create([
            'student_id' => $this->student->id,
            'section_id' => $this->section->id,
            'academic_year_id' => $year->id,
        ]);

        $this->teacher = User::factory()->role(UserRole::Teacher)->create(['school_id' => $this->school->id]);
        TeacherAssignment::factory()->create([
            'staff_id' => $this->teacher->id,
            'subject_id' => $this->subject->id,
            'section_id' => $this->section->id,
        ]);

        Sanctum::actingAs($this->teacher);
    }

    // ── ما يجب أن يبقى مفتوحاً ────────────────────────────────────────────

    public function test_a_teacher_takes_attendance_for_their_section(): void
    {
        $this->postJson("/api/sections/{$this->section->id}/attendance", [
            'date' => now()->toDateString(),
            'records' => [
                ['student_id' => $this->student->id, 'status' => 'present'],
            ],
        ])->assertSuccessful();
    }

    public function test_a_teacher_enters_grades_for_their_own_subject(): void
    {
        $type = AssessmentType::factory()->create(['school_id' => $this->school->id]);
        $assessment = Assessment::factory()->create([
            'subject_id' => $this->subject->id,
            'assessment_type_id' => $type->id,
            'max_score' => 100,
        ]);

        $this->postJson("/api/assessments/{$assessment->id}/scores", [
            'scores' => [
                ['student_id' => $this->student->id, 'score' => 85],
            ],
        ])->assertSuccessful();
    }

    public function test_a_teacher_reads_the_students_they_teach(): void
    {
        $this->getJson('/api/students')->assertOk();
    }

    // ── ما يجب أن يبقى مغلقاً ─────────────────────────────────────────────

    public function test_a_teacher_cannot_register_a_student(): void
    {
        $this->postJson('/api/students', [
            'first_name' => 'طالب',
            'last_name' => 'جديد',
        ])->assertForbidden();
    }

    public function test_a_teacher_cannot_see_tuition_plans(): void
    {
        $this->getJson('/api/fee-plans')->assertForbidden();
    }

    public function test_a_teacher_cannot_see_the_price_list(): void
    {
        // الخاصية المشتركة كانت تفتح القراءة لكل من في المدرسة، فتسلّم
        // المعلّم جدول الأقساط كاملاً.
        $this->getJson('/api/fee-types')->assertForbidden();
    }

    public function test_a_teacher_cannot_record_a_payment(): void
    {
        $year = AcademicYear::where('school_id', $this->school->id)->firstOrFail();
        $type = FeeType::factory()->create(['school_id' => $this->school->id]);
        $plan = FeePlan::factory()->create([
            'student_id' => $this->student->id,
            'academic_year_id' => $year->id,
            'fee_type_id' => $type->id,
            'total_minor' => 1000,
        ]);

        $this->postJson("/api/fee-plans/{$plan->id}/payments", [
            'paid_on' => now()->toDateString(),
            'amount' => 10,
        ])->assertForbidden();
    }

    public function test_a_teacher_cannot_browse_the_guardian_directory(): void
    {
        $guardianUser = User::factory()->role(UserRole::Guardian)->create(['school_id' => $this->school->id]);
        $guardian = Guardian::factory()->create(['school_id' => $this->school->id, 'user_id' => $guardianUser->id]);
        StudentGuardian::factory()->create(['student_id' => $this->student->id, 'guardian_id' => $guardian->id]);

        // قائمة أولياء الأمور هي أرقام هواتف المدرسة كلّها في صفحة واحدة.
        $this->getJson('/api/guardians')->assertForbidden();
    }

    public function test_a_teacher_cannot_create_a_staff_account(): void
    {
        // حمولة صحيحة عمداً: طلبٌ ناقص يُردّ ٤٢٢ فلا يثبت المنع شيئاً.
        $this->postJson('/api/users', [
            'first_name' => 'مستخدم',
            'last_name' => 'جديد',
            'phone' => '0955123456',
            'email' => 'new@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'admin',
        ])->assertForbidden();
    }

    public function test_a_teacher_cannot_open_an_academic_year(): void
    {
        $this->postJson('/api/academic-years', [
            'name' => '2027',
            'start_date' => '2027-09-01',
            'end_date' => '2028-06-30',
        ])->assertForbidden();
    }
}
