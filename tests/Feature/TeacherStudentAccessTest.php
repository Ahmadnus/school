<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\Guardian;
use App\Models\School;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\StudentGuardian;
use App\Models\TeacherAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * الأستاذ يُدرّس الطالب: يرى اسمه وشعبته وسجلّه، ولا يضيف طالباً ولا يحذفه،
 * ولا يرى أقساطه ولا ملفّه الشخصيّ (الميلاد، العنوان، الصحّة، الهواتف،
 * العائلة). الإدارة ووليّ الأمر يرونها كما كانت.
 */
class TeacherStudentAccessTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private Student $student;

    private User $teacher;

    private User $guardianUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $this->student = Student::factory()->create([
            'school_id' => $this->school->id,
            'address' => 'دمشق — المزة',
            'medical_notes' => 'حساسية',
            'emergency_contact_phone' => '0944000111',
        ]);
        $this->teacher = User::factory()->role(UserRole::Teacher)->create(['school_id' => $this->school->id]);

        // الأستاذ يدرّس شعبة الطالب — وإلّا لم يصله أصلاً.
        $year = AcademicYear::factory()->current()->create(['school_id' => $this->school->id]);
        $section = Section::factory()->create([
            'grade_id' => Grade::factory()->create(['school_id' => $this->school->id])->id,
            'academic_year_id' => $year->id,
        ]);
        StudentEnrollment::factory()->create([
            'student_id' => $this->student->id,
            'section_id' => $section->id,
            'academic_year_id' => $year->id,
        ]);
        TeacherAssignment::factory()->create(['staff_id' => $this->teacher->id, 'section_id' => $section->id]);

        $this->guardianUser = User::factory()->role(UserRole::Guardian)->create(['school_id' => $this->school->id]);
        $guardian = Guardian::factory()->create(['school_id' => $this->school->id, 'user_id' => $this->guardianUser->id]);
        StudentGuardian::factory()->create(['student_id' => $this->student->id, 'guardian_id' => $guardian->id]);
    }

    public function test_a_teacher_cannot_add_edit_or_delete_a_student(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/students', ['first_name' => 'جديد', 'last_name' => 'طالب'])->assertForbidden();
        $this->putJson("/api/students/{$this->student->id}", ['first_name' => 'معدّل'])->assertForbidden();
        $this->deleteJson("/api/students/{$this->student->id}")->assertForbidden();
        $this->assertModelExists($this->student);
    }

    public function test_a_teacher_sees_no_fees(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->getJson("/api/students/{$this->student->id}/fee-plans")->assertForbidden();
        $this->getJson("/api/students/{$this->student->id}/fee-statement")->assertForbidden();
        $this->getJson('/api/fee-plans')->assertForbidden();
        $this->getJson("/api/students/{$this->student->id}/profile")
            ->assertOk()
            ->assertJsonPath('data.permissions.view_fees', false)
            ->assertJsonPath('data.fees', null);
    }

    public function test_a_teacher_sees_the_name_but_not_the_personal_file(): void
    {
        Sanctum::actingAs($this->teacher);

        $student = $this->getJson("/api/students/{$this->student->id}")->assertOk()->json('data');
        $this->assertSame($this->student->full_name, $student['full_name']);
        foreach (['birth_date', 'address', 'medical_notes', 'phone', 'emergency_contact_phone', 'guardians'] as $field) {
            $this->assertArrayNotHasKey($field, $student, $field);
        }

        $this->getJson("/api/students/{$this->student->id}/profile")
            ->assertOk()
            ->assertJsonPath('data.permissions.view_personal', false)
            ->assertJsonMissingPath('data.student.address');
        $this->getJson("/api/students/{$this->student->id}/contacts")->assertForbidden();
        $this->getJson("/api/students/{$this->student->id}/guardians")->assertForbidden();

        // ولا يتسرّب من قائمة الطلاب.
        $row = collect($this->getJson('/api/students')->assertOk()->json('data'))->firstWhere('id', $this->student->id);
        $this->assertArrayNotHasKey('address', $row);
    }

    public function test_the_office_and_the_childs_guardian_still_see_everything(): void
    {
        Sanctum::actingAs(User::factory()->role(UserRole::Admin)->create(['school_id' => $this->school->id]));
        $this->getJson("/api/students/{$this->student->id}")
            ->assertOk()
            ->assertJsonPath('data.address', 'دمشق — المزة')
            ->assertJsonPath('data.medical_notes', 'حساسية');
        $this->getJson("/api/students/{$this->student->id}/contacts")->assertOk();

        Sanctum::actingAs($this->guardianUser);
        $this->getJson("/api/students/{$this->student->id}/profile")
            ->assertOk()
            ->assertJsonPath('data.permissions.view_personal', true)
            ->assertJsonPath('data.student.address', 'دمشق — المزة');
    }
}
