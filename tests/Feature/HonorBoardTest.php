<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\Guardian;
use App\Models\HonorEntry;
use App\Models\Notification;
use App\Models\School;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\StudentGuardian;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * لوحة الشرف: من يكرّم، ومن يرى، وما الذي لا يُعرض أبداً.
 */
class HonorBoardTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private Student $star;

    private Student $classmate;

    private User $admin;

    private User $teacher;

    private User $starGuardian;

    private User $otherGuardian;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $year = AcademicYear::factory()->current()->create(['school_id' => $this->school->id]);
        $grade = Grade::factory()->create(['school_id' => $this->school->id, 'name' => 'الثامن']);
        $section = Section::factory()->create([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'name' => 'أ',
        ]);

        $this->star = Student::factory()->create(['school_id' => $this->school->id]);
        $this->classmate = Student::factory()->create(['school_id' => $this->school->id]);

        foreach ([$this->star, $this->classmate] as $student) {
            StudentEnrollment::factory()->create([
                'student_id' => $student->id,
                'section_id' => $section->id,
                'academic_year_id' => $year->id,
            ]);
        }

        $this->admin = User::factory()->role(UserRole::Admin)->create(['school_id' => $this->school->id]);
        $this->teacher = User::factory()->role(UserRole::Teacher)->create(['school_id' => $this->school->id]);
        $this->starGuardian = $this->linkGuardian($this->star);
        $this->otherGuardian = $this->linkGuardian($this->classmate);
    }

    private function linkGuardian(Student $student): User
    {
        $user = User::factory()->role(UserRole::Guardian)->create(['school_id' => $this->school->id]);
        $guardian = Guardian::factory()->create(['school_id' => $this->school->id, 'user_id' => $user->id]);
        StudentGuardian::factory()->create(['student_id' => $student->id, 'guardian_id' => $guardian->id]);

        return $user;
    }

    private function award(array $overrides = []): int
    {
        return $this->postJson('/api/honor-entries', [
            'student_id' => $this->star->id,
            'category' => 'improvement',
            'reason' => 'ارتفع من 40 إلى 78 خلال شهرين',
            ...$overrides,
        ])->assertCreated()->json('data.id');
    }

    public function test_a_teacher_can_award_a_student(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/honor-entries', [
            'student_id' => $this->star->id,
            'category' => 'academic',
            'reason' => 'الأول في الرياضيات',
        ])->assertCreated()
            ->assertJsonPath('data.student_name', $this->star->full_name)
            ->assertJsonPath('data.section_label', 'الثامن - أ')
            ->assertJsonPath('data.category', 'academic')
            ->assertJsonPath('data.is_published', true);
    }

    public function test_the_award_never_carries_a_score(): void
    {
        Sanctum::actingAs($this->teacher);

        $payload = $this->postJson('/api/honor-entries', [
            'student_id' => $this->star->id,
            'category' => 'academic',
            'reason' => 'الأول في الرياضيات',
        ])->assertCreated()->json('data');

        // اللوحة تحفّز بلا أن تنشر درجات طالب على أهالي غيره.
        foreach (['score', 'average', 'grade_value', 'total'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $payload);
        }
    }

    public function test_the_awarded_family_gets_a_congratulation_and_others_get_the_board(): void
    {
        Sanctum::actingAs($this->teacher);
        $this->award();

        $own = Notification::where('user_id', $this->starGuardian->id)
            ->where('type', 'honor_board')->first();

        $this->assertNotNull($own);
        $this->assertStringContainsString($this->star->first_name, $own->title);
        $this->assertStringContainsString('ارتفع من 40', $own->body);

        // بقية الأهالي: خبر اللوحة بلا اسم في الإشعار.
        $others = Notification::where('user_id', $this->otherGuardian->id)
            ->where('type', 'honor_board')->first();

        $this->assertNotNull($others);
        $this->assertStringNotContainsString($this->star->first_name, $others->title.$others->body);
    }

    public function test_a_draft_notifies_nobody_until_published(): void
    {
        Sanctum::actingAs($this->teacher);

        $id = $this->award(['publish' => false]);

        $this->assertSame(0, Notification::where('type', 'honor_board')->count());

        $this->postJson("/api/honor-entries/{$id}/publish")->assertOk();

        $this->assertTrue(Notification::where('type', 'honor_board')->exists());
    }

    public function test_publishing_twice_is_rejected(): void
    {
        Sanctum::actingAs($this->teacher);
        $id = $this->award();

        $this->postJson("/api/honor-entries/{$id}/publish")->assertStatus(422);
    }

    public function test_every_family_in_the_school_sees_the_board(): void
    {
        Sanctum::actingAs($this->teacher);
        $this->award();

        // وليّ أمر طالب آخر تماماً يرى المكرَّم — هذا هو المقصد.
        Sanctum::actingAs($this->otherGuardian);

        $this->getJson('/api/honor-entries')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.student_name', $this->star->full_name)
            ->assertJsonPath('data.0.reason', 'ارتفع من 40 إلى 78 خلال شهرين');
    }

    public function test_guardians_never_see_drafts(): void
    {
        HonorEntry::factory()->create([
            'school_id' => $this->school->id,
            'student_id' => $this->star->id,
            'reason' => 'مسوّدة لم تُنشر',
        ]);

        Sanctum::actingAs($this->otherGuardian);

        $this->getJson('/api/honor-entries?include_drafts=1')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_staff_can_list_their_drafts(): void
    {
        HonorEntry::factory()->create([
            'school_id' => $this->school->id,
            'student_id' => $this->star->id,
            'reason' => 'مسوّدة لم تُنشر',
        ]);

        Sanctum::actingAs($this->teacher);

        $this->getJson('/api/honor-entries?include_drafts=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_published', false);
    }

    public function test_a_teacher_cannot_delete_a_colleagues_award(): void
    {
        Sanctum::actingAs($this->teacher);
        $id = $this->award();

        $colleague = User::factory()->role(UserRole::Teacher)->create(['school_id' => $this->school->id]);
        Sanctum::actingAs($colleague);

        $this->deleteJson("/api/honor-entries/{$id}")->assertForbidden();

        // لكن الإدارة تستطيع.
        Sanctum::actingAs($this->admin);
        $this->deleteJson("/api/honor-entries/{$id}")->assertOk();
    }

    public function test_a_guardian_cannot_award(): void
    {
        Sanctum::actingAs($this->starGuardian);

        $this->postJson('/api/honor-entries', [
            'student_id' => $this->star->id,
            'category' => 'academic',
            'reason' => 'ابني الأفضل',
        ])->assertForbidden();
    }

    public function test_an_award_needs_a_reason(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/honor-entries', [
            'student_id' => $this->star->id,
            'category' => 'academic',
        ])->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_the_board_of_another_school_is_not_visible(): void
    {
        Sanctum::actingAs($this->teacher);
        $this->award();

        $outsider = User::factory()->role(UserRole::Admin)->create([
            'school_id' => School::factory()->create()->id,
        ]);
        Sanctum::actingAs($outsider);

        $this->getJson('/api/honor-entries')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_board_filters_by_category(): void
    {
        Sanctum::actingAs($this->teacher);
        $this->award();
        $this->postJson('/api/honor-entries', [
            'student_id' => $this->classmate->id,
            'category' => 'attendance',
            'reason' => 'لم يتغيّب يوماً هذا الفصل',
        ])->assertCreated();

        $this->getJson('/api/honor-entries?category=attendance')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.student_id', $this->classmate->id);
    }
}
