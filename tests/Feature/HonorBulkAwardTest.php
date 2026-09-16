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
 * تكريم دفعة — «الأوائل الثلاثة في كل شعبة» بسبب واحد، وما يصل الأهالي بعدها.
 */
class HonorBulkAwardTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $teacher;

    /** @var array<int, Student> */
    private array $trio = [];

    private User $firstGuardian;

    private User $outsiderGuardian;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $year = AcademicYear::factory()->current()->create(['school_id' => $this->school->id]);
        $grade = Grade::factory()->create(['school_id' => $this->school->id, 'name' => 'السابع']);
        $section = Section::factory()->create([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
            'name' => 'أ',
        ]);

        for ($i = 0; $i < 3; $i++) {
            $student = Student::factory()->create(['school_id' => $this->school->id]);
            StudentEnrollment::factory()->create([
                'student_id' => $student->id,
                'section_id' => $section->id,
                'academic_year_id' => $year->id,
            ]);
            $this->trio[] = $student;
        }

        $this->teacher = User::factory()->role(UserRole::Teacher)->create(['school_id' => $this->school->id]);
        $this->firstGuardian = $this->linkGuardian($this->trio[0]);

        $outsider = Student::factory()->create(['school_id' => $this->school->id]);
        $this->outsiderGuardian = $this->linkGuardian($outsider);
    }

    private function linkGuardian(Student $student): User
    {
        $user = User::factory()->role(UserRole::Guardian)->create(['school_id' => $this->school->id]);
        $guardian = Guardian::factory()->create(['school_id' => $this->school->id, 'user_id' => $user->id]);
        StudentGuardian::factory()->create(['student_id' => $student->id, 'guardian_id' => $guardian->id]);

        return $user;
    }

    /** @return array<int, int> */
    private function ids(): array
    {
        return array_map(fn (Student $s) => $s->id, $this->trio);
    }

    public function test_three_students_are_awarded_in_one_call(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/honor-entries/bulk', [
            'student_ids' => $this->ids(),
            'category' => 'academic',
            'reason' => 'الأوائل الثلاثة للفصل الأول',
        ])->assertCreated()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('skipped', 0);

        $this->assertSame(3, HonorEntry::count());
        // السبب والصنف مشتركان للدفعة كلها.
        $this->assertSame(1, HonorEntry::query()->distinct()->count('reason'));
    }

    public function test_the_parents_of_each_awarded_student_get_a_congratulation(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/honor-entries/bulk', [
            'student_ids' => $this->ids(),
            'category' => 'academic',
            'reason' => 'الأوائل الثلاثة للفصل الأول',
        ])->assertCreated();

        $mine = Notification::query()
            ->where('user_id', $this->firstGuardian->id)
            ->where('type', 'honor_board')
            ->get();

        // تهنئة باسم ابنه — مرّة واحدة، لا ثلاث.
        $named = $mine->filter(fn ($n) => str_contains($n->title, $this->trio[0]->first_name));
        $this->assertCount(1, $named);
    }

    public function test_a_guardian_of_no_awarded_child_is_told_without_names(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/honor-entries/bulk', [
            'student_ids' => $this->ids(),
            'category' => 'academic',
            'reason' => 'الأوائل الثلاثة للفصل الأول',
        ])->assertCreated();

        $theirs = Notification::query()->where('user_id', $this->outsiderGuardian->id)->get();

        $this->assertNotEmpty($theirs);
        // لا يتسرّب اسم ابن غيره في إشعاره.
        foreach ($theirs as $notification) {
            foreach ($this->trio as $student) {
                $this->assertStringNotContainsString(
                    $student->first_name,
                    $notification->title.$notification->body,
                );
            }
        }
    }

    public function test_other_parents_get_one_notification_per_batch_not_per_student(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/honor-entries/bulk', [
            'student_ids' => $this->ids(),
            'category' => 'academic',
            'reason' => 'الأوائل الثلاثة للفصل الأول',
        ])->assertCreated();

        // ثلاثون شعبة × ثلاثة طلاب = تسعون إشعاراً متطابقاً لو أُرسل واحد
        // لكل طالب — فيُطفئ الأب الإشعارات ويضيع معها ما يهمّه.
        $this->assertSame(
            1,
            Notification::query()
                ->where('user_id', $this->outsiderGuardian->id)
                ->where('type', 'honor_board')
                ->count(),
        );
    }

    public function test_resending_the_same_batch_creates_nothing_and_re_notifies_nobody(): void
    {
        Sanctum::actingAs($this->teacher);

        $payload = [
            'student_ids' => $this->ids(),
            'category' => 'academic',
            'reason' => 'الأوائل الثلاثة للفصل الأول',
        ];

        $this->postJson('/api/honor-entries/bulk', $payload)->assertCreated();
        $after = Notification::count();

        $this->postJson('/api/honor-entries/bulk', $payload)
            ->assertCreated()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('skipped', 3);

        $this->assertSame(3, HonorEntry::count());
        $this->assertSame($after, Notification::count());
    }

    public function test_a_guardian_cannot_award_anyone(): void
    {
        Sanctum::actingAs($this->firstGuardian);

        $this->postJson('/api/honor-entries/bulk', [
            'student_ids' => $this->ids(),
            'category' => 'academic',
            'reason' => 'الأوائل الثلاثة للفصل الأول',
        ])->assertForbidden();

        $this->assertSame(0, HonorEntry::count());
    }

    public function test_a_student_of_another_school_is_rejected_and_nothing_is_written(): void
    {
        Sanctum::actingAs($this->teacher);

        $stranger = Student::factory()->create(['school_id' => School::factory()->create()->id]);

        $this->postJson('/api/honor-entries/bulk', [
            'student_ids' => [...$this->ids(), $stranger->id],
            'category' => 'academic',
            'reason' => 'الأوائل الثلاثة للفصل الأول',
        ])->assertStatus(422);

        // الدفعة كلّها أو لا شيء — لا تُكرَّم نصف الشعبة.
        $this->assertSame(0, HonorEntry::count());
    }

    public function test_the_guardian_home_card_shows_the_last_thirty_days_only(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/honor-entries/bulk', [
            'student_ids' => $this->ids(),
            'category' => 'academic',
            'reason' => 'الأوائل الثلاثة للفصل الأول',
        ])->assertCreated();

        Sanctum::actingAs($this->firstGuardian);

        $board = $this->getJson('/api/guardian/digest')->assertOk()->json('data.honor_board');

        $this->assertSame(3, $board['total']);
        $this->assertCount(1, $board['mine']);
        $this->assertSame($this->trio[0]->first_name, $board['mine'][0]['student_name']);

        // بعد أربعين يوماً تنطفئ البطاقة وحدها، واللوحة الكاملة تبقى سجلاً.
        HonorEntry::query()->update(['published_at' => now()->subDays(40)]);

        $later = $this->getJson('/api/guardian/digest')->assertOk()->json('data.honor_board');

        $this->assertSame(0, $later['total']);
        $this->assertCount(0, $later['mine']);
        $this->assertSame(3, HonorEntry::count());
    }
}
