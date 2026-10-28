<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AcademicYear;
use App\Models\FeePlan;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\Guardian;
use App\Models\Notification;
use App\Models\School;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * القبول والتسجيل: مسار الحالات، والتسجيل يُنشئ الطالب وولي أمره وخطته دون أن
 * يُرسل شيئاً لأحد، ولا يتكرّر.
 */
class AdmissionTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $admin;

    private Grade $grade;

    private Section $section;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = School::factory()->create();
        $year = AcademicYear::factory()->current()->create(['school_id' => $this->school->id]);
        $this->grade = Grade::factory()->create(['school_id' => $this->school->id]);
        $this->section = Section::factory()->create([
            'grade_id' => $this->grade->id, 'academic_year_id' => $year->id,
        ]);
        $this->admin = User::factory()->role(UserRole::Admin)->create(['school_id' => $this->school->id]);
        Sanctum::actingAs($this->admin);
    }

    private function apply(array $extra = []): array
    {
        return $this->postJson('/api/admissions', [
            'first_name' => 'سامر', 'last_name' => 'الأحمد', 'birth_date' => '2016-05-01',
            'gender' => 'male', 'desired_grade_id' => $this->grade->id,
            'guardian_name' => 'أبو سامر', 'guardian_phone' => '0955123456',
            ...$extra,
        ])->assertCreated()->json('data');
    }

    private function accept(int $id): void
    {
        $this->postJson("/api/admissions/{$id}/status", ['status' => 'accepted'])->assertOk();
    }

    public function test_the_pipeline_moves_forward_and_refuses_illegal_jumps(): void
    {
        $a = $this->apply();

        $this->postJson("/api/admissions/{$a['id']}/status", [
            'status' => 'interview', 'interview_at' => '2026-11-02 10:00:00',
        ])->assertOk()->assertJsonPath('data.status', 'interview');

        // لا قفز إلى «مسجَّل» يدوياً، ولا من مرفوض إلى مقبول مباشرة.
        $this->postJson("/api/admissions/{$a['id']}/status", ['status' => 'enrolled'])->assertUnprocessable();
        $this->postJson("/api/admissions/{$a['id']}/status", ['status' => 'rejected'])->assertOk();
        $this->postJson("/api/admissions/{$a['id']}/status", ['status' => 'accepted'])->assertUnprocessable();
        $this->postJson("/api/admissions/{$a['id']}/status", ['status' => 'new'])->assertOk();
    }

    public function test_enrolling_creates_student_guardian_enrollment_and_fee_plan(): void
    {
        FeeType::factory()->create([
            'school_id' => $this->school->id, 'grade_id' => $this->grade->id, 'is_default' => true,
            'total_minor' => '2000000',
        ]);
        $a = $this->apply();
        $this->accept($a['id']);

        $res = $this->postJson("/api/admissions/{$a['id']}/enroll", ['section_id' => $this->section->id])
            ->assertOk()->assertJsonPath('data.status', 'enrolled');

        $student = Student::findOrFail($res->json('data.student_id'));
        $this->assertSame($this->section->id, $student->currentEnrollment->section_id);
        $this->assertSame('0955123456', $student->guardians()->first()->phone);
        $this->assertSame(1, FeePlan::where('student_id', $student->id)->count());
    }

    public function test_an_existing_guardian_is_reused_by_phone_and_nothing_is_sent_to_anyone(): void
    {
        $guardian = Guardian::factory()->create(['school_id' => $this->school->id, 'phone' => '0955123456']);
        $before = Notification::count();

        $a = $this->apply();
        $this->accept($a['id']);
        $this->postJson("/api/admissions/{$a['id']}/enroll", ['section_id' => $this->section->id])->assertOk();

        $this->assertSame(1, Guardian::count());
        $this->assertSame($guardian->id, Student::firstOrFail()->guardians()->first()->id);
        $this->assertSame($before, Notification::count());
    }

    public function test_only_accepted_applications_enrol_and_only_once(): void
    {
        $a = $this->apply();
        $this->postJson("/api/admissions/{$a['id']}/enroll", ['section_id' => $this->section->id])->assertUnprocessable();

        $this->accept($a['id']);
        $this->postJson("/api/admissions/{$a['id']}/enroll", ['section_id' => $this->section->id])->assertOk();
        $this->postJson("/api/admissions/{$a['id']}/enroll", ['section_id' => $this->section->id])->assertUnprocessable();

        $this->assertSame(1, Student::count());
        $this->putJson("/api/admissions/{$a['id']}", ['first_name' => 'غيره', 'last_name' => 'x'])->assertUnprocessable();
    }

    public function test_a_duplicate_student_is_blocked_unless_forced(): void
    {
        Student::factory()->create([
            'school_id' => $this->school->id, 'first_name' => 'سامر', 'last_name' => 'الأحمد',
            'birth_date' => '2016-05-01',
        ]);
        $a = $this->apply();
        $this->accept($a['id']);

        $this->postJson("/api/admissions/{$a['id']}/enroll", ['section_id' => $this->section->id])->assertStatus(409);
        $this->postJson("/api/admissions/{$a['id']}/enroll", ['section_id' => $this->section->id, 'force' => true])->assertOk();
    }

    public function test_a_section_of_another_school_is_rejected(): void
    {
        $other = School::factory()->create();
        $y = AcademicYear::factory()->current()->create(['school_id' => $other->id]);
        $foreign = Section::factory()->create([
            'grade_id' => Grade::factory()->create(['school_id' => $other->id])->id, 'academic_year_id' => $y->id,
        ]);
        $a = $this->apply();
        $this->accept($a['id']);

        $this->postJson("/api/admissions/{$a['id']}/enroll", ['section_id' => $foreign->id])->assertNotFound();
        $this->assertSame(0, Student::count());
    }

    public function test_admins_only_and_school_isolation_and_summary(): void
    {
        $this->apply();
        $this->apply(['first_name' => 'ليلى', 'gender' => 'female']);

        $this->getJson('/api/admissions/summary')->assertOk()->assertJsonPath('data.new', 2);
        $this->getJson('/api/admissions?q=ليلى')->assertOk()->assertJsonCount(1, 'data');

        $other = User::factory()->role(UserRole::Admin)->create(['school_id' => School::factory()->create()->id]);
        Sanctum::actingAs($other);
        $this->getJson('/api/admissions')->assertOk()->assertJsonCount(0, 'data');

        $teacher = User::factory()->role(UserRole::Teacher)->create(['school_id' => $this->school->id]);
        Sanctum::actingAs($teacher);
        $this->getJson('/api/admissions')->assertForbidden();
        $this->postJson('/api/admissions', ['first_name' => 'a', 'last_name' => 'b'])->assertForbidden();
    }
}
