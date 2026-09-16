<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\UserRole;
use App\Models\AcademicYear;
use App\Models\AttendanceRecord;
use App\Models\BehaviorRecord;
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

class GuardianDigestTest extends TestCase
{
    use RefreshDatabase;

    public function test_digest_is_guardian_only_and_summarises_each_child(): void
    {
        $school = School::factory()->create();
        $year = AcademicYear::factory()->current()->create(['school_id' => $school->id]);
        $grade = Grade::factory()->create(['school_id' => $school->id]);
        $section = Section::factory()->create(['grade_id' => $grade->id, 'academic_year_id' => $year->id]);

        $child = Student::factory()->create(['school_id' => $school->id]);
        $stranger = Student::factory()->create(['school_id' => $school->id]);
        foreach ([$child, $stranger] as $s) {
            StudentEnrollment::factory()->create([
                'student_id' => $s->id, 'section_id' => $section->id, 'academic_year_id' => $year->id,
            ]);
        }

        $guardianUser = User::factory()->role(UserRole::Guardian)->create(['school_id' => $school->id]);
        $guardian = Guardian::factory()->create(['school_id' => $school->id, 'user_id' => $guardianUser->id]);
        StudentGuardian::factory()->create(['student_id' => $child->id, 'guardian_id' => $guardian->id]);

        // This week: one present, one late (Carbon week starts Saturday in the digest).
        $today = now();
        AttendanceRecord::factory()->create([
            'student_id' => $child->id, 'section_id' => $section->id,
            'date' => $today->toDateString(), 'status' => AttendanceStatus::Late,
        ]);
        AttendanceRecord::factory()->create([
            'student_id' => $stranger->id, 'section_id' => $section->id,
            'date' => $today->toDateString(), 'status' => AttendanceStatus::Absent,
        ]);
        BehaviorRecord::query()->create([
            'student_id' => $child->id, 'type' => 'positive', 'title' => 'x',
            'occurred_on' => $today->toDateString(), 'visible_to_guardian' => true,
        ]);
        BehaviorRecord::query()->create([
            'student_id' => $child->id, 'type' => 'incident', 'title' => 'y',
            'occurred_on' => $today->toDateString(), 'visible_to_guardian' => false,
        ]);
        $plan = FeePlan::factory()->create(['student_id' => $child->id, 'academic_year_id' => $year->id]);
        FeePlanInstallment::factory()->create([
            'fee_plan_id' => $plan->id, 'due_date' => $today->copy()->subDays(3)->toDateString(), 'amount_minor' => '250.00',
        ]);

        $admin = User::factory()->role(UserRole::Admin)->create(['school_id' => $school->id]);
        Sanctum::actingAs($admin);
        $this->getJson('/api/guardian/digest')->assertForbidden();

        Sanctum::actingAs($guardianUser);
        $response = $this->getJson('/api/guardian/digest')->assertOk();

        $children = $response->json('data.children');
        $this->assertCount(1, $children);
        $this->assertSame($child->id, $children[0]['student']['id']);
        $this->assertSame(1, $children[0]['attendance_week']['late']);
        $this->assertSame(1, $children[0]['attendance_week']['recorded']);
        $this->assertSame(1, $children[0]['recent_behavior_shared']);
        $this->assertTrue($children[0]['next_installment']['overdue']);
        $this->assertSame('250.00', $children[0]['next_installment']['remaining']);
        $this->assertNotEmpty($children[0]['attention_items']);
    }
}
