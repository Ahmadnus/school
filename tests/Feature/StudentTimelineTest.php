<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\UserRole;
use App\Models\AcademicYear;
use App\Models\AttendanceRecord;
use App\Models\BehaviorRecord;
use App\Models\FeePayment;
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

class StudentTimelineTest extends TestCase
{
    use RefreshDatabase;

    private Student $child;

    private User $admin;

    private User $teacher;

    private User $guardianUser;

    protected function setUp(): void
    {
        parent::setUp();

        $school = School::factory()->create();
        $year = AcademicYear::factory()->current()->create([
            'school_id' => $school->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);
        $grade = Grade::factory()->create(['school_id' => $school->id]);
        $section = Section::factory()->create(['grade_id' => $grade->id, 'academic_year_id' => $year->id]);

        $this->child = Student::factory()->create(['school_id' => $school->id]);
        StudentEnrollment::factory()->create([
            'student_id' => $this->child->id, 'section_id' => $section->id,
            'academic_year_id' => $year->id, 'enrolled_at' => '2026-09-01',
        ]);

        $this->admin = User::factory()->role(UserRole::Admin)->create(['school_id' => $school->id]);
        $this->teacher = User::factory()->role(UserRole::Teacher)->create(['school_id' => $school->id]);
        $this->guardianUser = User::factory()->role(UserRole::Guardian)->create(['school_id' => $school->id]);
        $guardian = Guardian::factory()->create(['school_id' => $school->id, 'user_id' => $this->guardianUser->id]);
        StudentGuardian::factory()->create(['student_id' => $this->child->id, 'guardian_id' => $guardian->id]);

        AttendanceRecord::factory()->create([
            'student_id' => $this->child->id, 'section_id' => $section->id,
            'date' => '2026-09-03', 'status' => AttendanceStatus::Absent,
        ]);
        StudentNote::query()->create(['student_id' => $this->child->id, 'author_id' => $this->teacher->id, 'body' => 'سرّي']);
        BehaviorRecord::query()->create([
            'student_id' => $this->child->id, 'recorded_by' => $this->teacher->id, 'type' => 'incident',
            'title' => 'داخلي', 'occurred_on' => '2026-09-04', 'visible_to_guardian' => false,
        ]);
        BehaviorRecord::query()->create([
            'student_id' => $this->child->id, 'recorded_by' => $this->teacher->id, 'type' => 'positive',
            'title' => 'مشترك', 'occurred_on' => '2026-09-05', 'visible_to_guardian' => true,
        ]);
        $plan = FeePlan::factory()->create(['student_id' => $this->child->id, 'academic_year_id' => $year->id]);
        FeePayment::factory()->create(['fee_plan_id' => $plan->id, 'paid_on' => '2026-09-06', 'amount_minor' => '100.00']);
    }

    public function test_admin_sees_every_source_newest_first(): void
    {
        Sanctum::actingAs($this->admin);

        $data = $this->getJson("/api/students/{$this->child->id}/timeline")->assertOk()->json('data');
        $types = collect($data)->pluck('type');

        foreach (['enrollment', 'attendance', 'behavior', 'note', 'payment', 'guardian'] as $type) {
            $this->assertTrue($types->contains($type), "missing $type");
        }
        $dates = collect($data)->pluck('date')->all();
        $sorted = $dates;
        rsort($sorted);
        $this->assertSame($sorted, $dates);
    }

    public function test_teacher_sees_notes_but_no_payments(): void
    {
        Sanctum::actingAs($this->teacher);

        $types = collect($this->getJson("/api/students/{$this->child->id}/timeline")->assertOk()->json('data'))->pluck('type');

        $this->assertTrue($types->contains('note'));
        $this->assertFalse($types->contains('payment'));
    }

    public function test_guardian_gets_shared_behavior_only_and_no_notes(): void
    {
        Sanctum::actingAs($this->guardianUser);

        $data = collect($this->getJson("/api/students/{$this->child->id}/timeline")->assertOk()->json('data'));

        $this->assertFalse($data->pluck('type')->contains('note'));
        $behavior = $data->where('type', 'behavior');
        $this->assertCount(1, $behavior);
        $this->assertStringContainsString('مشترك', $behavior->first()['title']);
        // Money is allowed for the child's own guardian.
        $this->assertTrue($data->pluck('type')->contains('payment'));
    }

    public function test_type_filter_and_pagination_meta(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->getJson("/api/students/{$this->child->id}/timeline?types[]=attendance&per_page=1")->assertOk();
        $this->assertSame(['attendance'], collect($response->json('data'))->pluck('type')->unique()->values()->all());
        $this->assertSame(1, $response->json('meta.total'));
    }
}
