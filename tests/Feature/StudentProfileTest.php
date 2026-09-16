<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\UserRole;
use App\Models\AbsenceExcuse;
use App\Models\AcademicYear;
use App\Models\AttendanceRecord;
use App\Models\BehaviorRecord;
use App\Models\FeePlan;
use App\Models\Grade;
use App\Models\Guardian;
use App\Models\School;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\StudentGuardian;
use App\Models\StudentNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The student profile is the most privacy-sensitive surface of the API: a
 * guardian must never reach another family's child, and internal notes must
 * never leave the staff app. These tests pin those rules.
 */
class StudentProfileTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private AcademicYear $year;

    private Section $section;

    private Student $child;

    private Student $otherChild;

    private User $admin;

    private User $teacher;

    private User $driver;

    private User $guardianUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $this->year = AcademicYear::factory()->current()->create(['school_id' => $this->school->id]);
        $grade = Grade::factory()->create(['school_id' => $this->school->id]);
        $this->section = Section::factory()->create([
            'grade_id' => $grade->id,
            'academic_year_id' => $this->year->id,
        ]);

        $this->child = Student::factory()->create(['school_id' => $this->school->id]);
        $this->otherChild = Student::factory()->create(['school_id' => $this->school->id]);

        foreach ([$this->child, $this->otherChild] as $student) {
            StudentEnrollment::factory()->create([
                'student_id' => $student->id,
                'section_id' => $this->section->id,
                'academic_year_id' => $this->year->id,
            ]);
        }

        $this->admin = User::factory()->role(UserRole::Admin)->create(['school_id' => $this->school->id]);
        $this->teacher = User::factory()->role(UserRole::Teacher)->create(['school_id' => $this->school->id]);
        $this->driver = User::factory()->role(UserRole::Driver)->create(['school_id' => $this->school->id]);

        $this->guardianUser = User::factory()->role(UserRole::Guardian)->create(['school_id' => $this->school->id]);
        $guardian = Guardian::factory()->create([
            'school_id' => $this->school->id,
            'user_id' => $this->guardianUser->id,
        ]);
        StudentGuardian::factory()->create([
            'student_id' => $this->child->id,
            'guardian_id' => $guardian->id,
            'is_primary' => true,
        ]);
    }

    public function test_admin_gets_full_profile_permissions(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->getJson("/api/students/{$this->child->id}/profile");

        $response->assertOk()
            ->assertJsonPath('data.student.id', $this->child->id)
            ->assertJsonPath('data.permissions.update', true)
            ->assertJsonPath('data.permissions.view_notes', true)
            ->assertJsonPath('data.permissions.view_fees', true)
            ->assertJsonPath('data.permissions.manage_behavior', true);
    }

    public function test_guardian_sees_own_child_but_not_another(): void
    {
        Sanctum::actingAs($this->guardianUser);

        $this->getJson("/api/students/{$this->child->id}/profile")
            ->assertOk()
            ->assertJsonPath('data.permissions.view_notes', false)
            ->assertJsonPath('data.permissions.view_fees', true)
            ->assertJsonPath('data.permissions.update', false);

        $this->getJson("/api/students/{$this->otherChild->id}/profile")->assertForbidden();
        $this->getJson("/api/students/{$this->otherChild->id}")->assertForbidden();
        $this->getJson("/api/students/{$this->otherChild->id}/guardians")->assertForbidden();
        $this->getJson("/api/students/{$this->otherChild->id}/enrollments")->assertForbidden();
        $this->getJson("/api/students/{$this->otherChild->id}/fee-plans")->assertForbidden();
    }

    public function test_internal_notes_are_staff_only(): void
    {
        StudentNote::query()->create([
            'student_id' => $this->child->id,
            'author_id' => $this->teacher->id,
            'body' => 'ملاحظة داخلية',
        ]);

        Sanctum::actingAs($this->guardianUser);
        $this->getJson("/api/students/{$this->child->id}/notes")->assertForbidden();
        $this->postJson("/api/students/{$this->child->id}/notes", ['body' => 'x'])->assertForbidden();

        Sanctum::actingAs($this->driver);
        $this->getJson("/api/students/{$this->child->id}/notes")->assertForbidden();

        Sanctum::actingAs($this->teacher);
        $this->getJson("/api/students/{$this->child->id}/notes")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.can_manage', true);

        $created = $this->postJson("/api/students/{$this->child->id}/notes", ['body' => 'جديدة'])
            ->assertCreated()
            ->json('data.id');

        // An administrator may edit a teacher's note; another teacher may not.
        $otherTeacher = User::factory()->role(UserRole::Teacher)->create(['school_id' => $this->school->id]);
        Sanctum::actingAs($otherTeacher);
        $this->putJson("/api/student-notes/{$created}", ['body' => 'تعديل'])->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->putJson("/api/student-notes/{$created}", ['body' => 'تعديل'])->assertOk();
        $this->deleteJson("/api/student-notes/{$created}")->assertOk();
    }

    public function test_guardian_only_sees_shared_behavior_records(): void
    {
        BehaviorRecord::query()->create([
            'student_id' => $this->child->id,
            'recorded_by' => $this->teacher->id,
            'type' => 'positive',
            'title' => 'مشاركة ممتازة',
            'occurred_on' => now()->toDateString(),
            'visible_to_guardian' => true,
        ]);
        BehaviorRecord::query()->create([
            'student_id' => $this->child->id,
            'recorded_by' => $this->teacher->id,
            'type' => 'incident',
            'title' => 'حادثة داخلية',
            'occurred_on' => now()->toDateString(),
            'visible_to_guardian' => false,
        ]);

        Sanctum::actingAs($this->teacher);
        $this->getJson("/api/students/{$this->child->id}/behavior")->assertOk()->assertJsonCount(2, 'data');

        Sanctum::actingAs($this->guardianUser);
        $this->getJson("/api/students/{$this->child->id}/behavior")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'positive');
        $this->postJson("/api/students/{$this->child->id}/behavior", [
            'type' => 'positive', 'title' => 'x', 'occurred_on' => now()->toDateString(),
        ])->assertForbidden();

        Sanctum::actingAs($this->driver);
        $this->getJson("/api/students/{$this->child->id}/behavior")->assertForbidden();
    }

    public function test_teacher_creates_behavior_record_with_validation(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson("/api/students/{$this->child->id}/behavior", ['title' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type', 'occurred_on']);

        $this->postJson("/api/students/{$this->child->id}/behavior", [
            'type' => 'warning',
            'title' => 'تأخر متكرر',
            'occurred_on' => now()->toDateString(),
            'action_taken' => 'تنبيه شفهي',
        ])->assertCreated()
            ->assertJsonPath('data.type', 'warning')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.recorded_by', $this->teacher->id);
    }

    public function test_fees_are_hidden_from_teachers_but_shown_to_own_guardian(): void
    {
        FeePlan::factory()->create([
            'student_id' => $this->child->id,
            'academic_year_id' => $this->year->id,
        ]);

        Sanctum::actingAs($this->teacher);
        $this->getJson("/api/students/{$this->child->id}/fee-plans")->assertForbidden();
        $this->getJson("/api/students/{$this->child->id}/profile")
            ->assertOk()
            ->assertJsonPath('data.permissions.view_fees', false)
            ->assertJsonPath('data.fees', null);

        Sanctum::actingAs($this->guardianUser);
        $this->getJson("/api/students/{$this->child->id}/fee-plans")->assertOk()->assertJsonCount(1, 'data');
        // The general list is scoped to the guardian's children too.
        $this->getJson('/api/fee-plans')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_attendance_summary_derives_excused_absences_and_rates(): void
    {
        $days = ['2026-09-01' => 'present', '2026-09-02' => 'late', '2026-09-03' => 'absent', '2026-09-06' => 'absent'];
        foreach ($days as $date => $status) {
            AttendanceRecord::factory()->create([
                'student_id' => $this->child->id,
                'section_id' => $this->section->id,
                'date' => $date,
                'status' => AttendanceStatus::from($status),
            ]);
        }
        AbsenceExcuse::factory()->accepted()->create([
            'student_id' => $this->child->id,
            'start_date' => '2026-09-03',
            'end_date' => '2026-09-03',
        ]);

        Sanctum::actingAs($this->admin);
        $this->getJson("/api/students/{$this->child->id}/attendance-summary")
            ->assertOk()
            ->assertJsonPath('data.present', 1)
            ->assertJsonPath('data.late', 1)
            ->assertJsonPath('data.absent', 2)
            ->assertJsonPath('data.excused', 1)
            ->assertJsonPath('data.unexcused', 1)
            ->assertJsonPath('data.recorded', 4)
            ->assertJsonPath('data.attendance_rate', 50)
            ->assertJsonPath('data.effective_rate', 66.7);

        // The log flags the excused day and carries the reason.
        $rows = $this->getJson("/api/attendance?student_id={$this->child->id}&status=excused")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->json('data');
        $this->assertSame('2026-09-03', $rows[0]['date']);
        $this->assertTrue($rows[0]['is_excused']);
        $this->assertSame('accepted', $rows[0]['excuse']['status']);

        // A guardian sees only their own child's attendance.
        AttendanceRecord::factory()->create([
            'student_id' => $this->otherChild->id,
            'section_id' => $this->section->id,
            'date' => '2026-09-01',
        ]);
        Sanctum::actingAs($this->guardianUser);
        $this->getJson('/api/attendance')->assertOk()->assertJsonCount(4, 'data');
        $this->getJson("/api/students/{$this->otherChild->id}/attendance-summary")->assertForbidden();
    }

    public function test_guardian_files_excuse_for_own_child_only(): void
    {
        Sanctum::actingAs($this->guardianUser);

        $this->postJson('/api/excuses', [
            'student_id' => $this->child->id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
            'reason' => 'مرض',
        ])->assertCreated();

        $this->postJson('/api/excuses', [
            'student_id' => $this->otherChild->id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
        ])->assertForbidden();

        $this->getJson('/api/excuses')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_submitted_roll_call_notifies_guardian_and_flags_threshold(): void
    {
        $this->school->update(['absence_warning_threshold' => 1]);

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/sections/{$this->section->id}/attendance", [
            'date' => '2026-09-08',
            'submit' => true,
            'records' => [
                ['student_id' => $this->child->id, 'status' => 'absent'],
                ['student_id' => $this->otherChild->id, 'status' => 'present'],
            ],
        ])->assertOk();

        // The guardian is told about the absence; the office about the threshold.
        $this->assertDatabaseHas('notifications', ['user_id' => $this->guardianUser->id, 'type' => 'attendance_absence']);
        $this->assertDatabaseHas('notifications', ['user_id' => $this->admin->id, 'type' => 'attendance_absence']);
        $this->assertDatabaseHas('notifications', ['user_id' => $this->admin->id, 'type' => 'attendance_summary']);

        $this->getJson("/api/students/{$this->child->id}/profile")
            ->assertOk()
            ->assertJsonPath('data.attendance.warning_threshold', 1)
            ->assertJsonPath('data.attendance.over_threshold', true);
    }

    public function test_subjects_and_contacts_endpoints_respond(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson("/api/students/{$this->child->id}/subjects")
            ->assertOk()
            ->assertJsonPath('data.enrollment.section_id', $this->section->id)
            ->assertJsonPath('data.subjects', []);

        $this->child->update(['emergency_contact_name' => 'خالد', 'emergency_contact_phone' => '0911111111']);

        $contacts = $this->getJson("/api/students/{$this->child->id}/contacts")->assertOk()->json('data');
        $kinds = array_column($contacts, 'kind');
        $this->assertContains('guardian', $kinds);
        $this->assertContains('emergency', $kinds);
    }
}
