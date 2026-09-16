<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\UserRole;
use App\Models\AcademicYear;
use App\Models\AttendanceRecord;
use App\Models\FeePlan;
use App\Models\FeePlanInstallment;
use App\Models\Grade;
use App\Models\Guardian;
use App\Models\School;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\StudentGuardian;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentSignalsTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

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

        $this->school = School::factory()->create(['absence_warning_threshold' => 2]);
        $year = AcademicYear::factory()->current()->create(['school_id' => $this->school->id]);
        $grade = Grade::factory()->create(['school_id' => $this->school->id]);
        $this->section = Section::factory()->create(['grade_id' => $grade->id, 'academic_year_id' => $year->id]);

        $this->child = Student::factory()->create(['school_id' => $this->school->id]);
        $this->otherChild = Student::factory()->create(['school_id' => $this->school->id]);
        foreach ([$this->child, $this->otherChild] as $s) {
            StudentEnrollment::factory()->create([
                'student_id' => $s->id, 'section_id' => $this->section->id, 'academic_year_id' => $year->id,
            ]);
        }

        $this->admin = User::factory()->role(UserRole::Admin)->create(['school_id' => $this->school->id]);
        $this->teacher = User::factory()->role(UserRole::Teacher)->create(['school_id' => $this->school->id]);
        $this->driver = User::factory()->role(UserRole::Driver)->create(['school_id' => $this->school->id]);
        $this->guardianUser = User::factory()->role(UserRole::Guardian)->create(['school_id' => $this->school->id]);
        $guardian = Guardian::factory()->create(['school_id' => $this->school->id, 'user_id' => $this->guardianUser->id]);
        StudentGuardian::factory()->create(['student_id' => $this->child->id, 'guardian_id' => $guardian->id]);

        // Two unexcused absences → hits the school threshold of 2.
        foreach (['2026-09-01', '2026-09-02'] as $date) {
            AttendanceRecord::factory()->create([
                'student_id' => $this->child->id, 'section_id' => $this->section->id,
                'date' => $date, 'status' => AttendanceStatus::Absent,
            ]);
        }
        // An overdue installment.
        $plan = FeePlan::factory()->create(['student_id' => $this->child->id, 'academic_year_id' => $year->id]);
        FeePlanInstallment::factory()->create([
            'fee_plan_id' => $plan->id, 'due_date' => now()->subDays(10)->toDateString(), 'amount_minor' => '500.00',
        ]);
    }

    public function test_admin_sees_threshold_and_fee_signals(): void
    {
        Sanctum::actingAs($this->admin);

        $data = $this->getJson("/api/students/{$this->child->id}/signals")->assertOk()->json('data');
        $keys = collect($data['signals'])->pluck('key');

        $this->assertTrue($keys->contains('unexcused_absences'));
        $this->assertTrue($keys->contains('overdue_fees'));
        $this->assertSame('attention', $data['level']);

        // Embedded in the profile bundle as well.
        $this->getJson("/api/students/{$this->child->id}/profile")
            ->assertOk()
            ->assertJsonPath('data.signals.level', 'attention');
    }

    public function test_teacher_never_receives_the_fee_signal(): void
    {
        Sanctum::actingAs($this->teacher);

        $keys = collect($this->getJson("/api/students/{$this->child->id}/signals")->assertOk()->json('data.signals'))
            ->pluck('key');

        $this->assertTrue($keys->contains('unexcused_absences'));
        $this->assertFalse($keys->contains('overdue_fees'));
    }

    public function test_attention_list_is_staff_only_and_lists_flagged_students(): void
    {
        Sanctum::actingAs($this->guardianUser);
        $this->getJson('/api/students/attention')->assertForbidden();

        Sanctum::actingAs($this->driver);
        $this->getJson('/api/students/attention')->assertForbidden();

        Sanctum::actingAs($this->admin);
        $response = $this->getJson('/api/students/attention')->assertOk();
        $ids = collect($response->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($this->child->id));
        $this->assertFalse($ids->contains($this->otherChild->id));
        $this->assertSame(1, $response->json('meta.total'));

        $this->getJson('/api/students/attention?level=follow_up')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_guardian_signals_only_for_own_child(): void
    {
        Sanctum::actingAs($this->guardianUser);

        $this->getJson("/api/students/{$this->child->id}/signals")->assertOk();
        $this->getJson("/api/students/{$this->otherChild->id}/signals")->assertForbidden();
    }
}
