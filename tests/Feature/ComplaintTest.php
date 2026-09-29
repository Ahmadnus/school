<?php

namespace Tests\Feature;

use App\Enums\ComplaintCategory;
use App\Enums\ConversationType;
use App\Enums\UserRole;
use App\Models\Conversation;
use App\Models\Guardian;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\User;
use App\Services\GuardianAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * الشكوى: إلى الإدارة وحدها، ولو كانت على أستاذ.
 *
 * وهذا القرار هو الميزة نفسها: لو رأى الأستاذُ المشكوّ عليه الشكوى لما اشتكى
 * وليُّ أمرٍ بصراحةٍ أبداً — خوفاً على ابنه. فالحرس هنا ليس تفصيلاً تقنيّاً.
 */
class ComplaintTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $admin;

    private User $teacher;

    private User $guardian;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $this->admin = User::factory()->role(UserRole::Admin)->create(['school_id' => $this->school->id]);
        $this->teacher = User::factory()->role(UserRole::Teacher)->create(['school_id' => $this->school->id]);

        $this->student = Student::factory()->create(['school_id' => $this->school->id]);
        $guardian = Guardian::factory()->create(['school_id' => $this->school->id]);
        StudentGuardian::factory()->create([
            'student_id' => $this->student->id,
            'guardian_id' => $guardian->id,
        ]);
        $this->guardian = GuardianAccount::for($guardian);
    }

    /** @param  array<string, mixed>  $extra */
    private function file(array $extra = []): TestResponse
    {
        return $this->postJson('/api/conversations', [
            'type' => 'complaints',
            'body' => 'نصّ الشكوى',
            ...$extra,
        ]);
    }

    public function test_a_guardian_may_complain_about_a_teacher(): void
    {
        Sanctum::actingAs($this->guardian);

        $id = $this->file(['about_staff_id' => $this->teacher->id])
            ->assertCreated()
            ->json('data.id');

        $complaint = Conversation::findOrFail($id);

        $this->assertSame(ConversationType::Complaints, $complaint->type);
        $this->assertSame($this->teacher->id, $complaint->about_staff_id);
        $this->assertTrue($complaint->isAboutStaff());
    }

    public function test_the_teacher_complained_about_can_never_read_it(): void
    {
        Sanctum::actingAs($this->guardian);
        $id = $this->file(['about_staff_id' => $this->teacher->id])->assertCreated()->json('data.id');

        Sanctum::actingAs($this->teacher);

        // لا طرفاً في الخيط، فلا يُدرَج في أي تبويب ولا يُفتَح بالمعرّف.
        $this->assertEmpty($this->getJson('/api/conversations?type=complaints')->json('data'));
        $this->assertEmpty($this->getJson('/api/conversations?type=staff')->json('data'));
        $this->assertEmpty($this->getJson('/api/conversations?type=guardians')->json('data'));
        $this->getJson("/api/conversations/{$id}")->assertForbidden();
        $this->getJson("/api/conversations/{$id}/messages")->assertForbidden();
    }

    public function test_the_office_receives_it_and_can_reply(): void
    {
        Sanctum::actingAs($this->guardian);
        $id = $this->file(['complaint_category' => 'canteen'])->assertCreated()->json('data.id');

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/conversations?type=complaints')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.complaint_category', 'canteen');

        $this->postJson("/api/conversations/{$id}/messages", ['body' => 'وصلتنا'])
            ->assertCreated();
    }

    public function test_a_complaint_stays_out_of_the_other_two_tabs(): void
    {
        Sanctum::actingAs($this->guardian);
        $this->file(['complaint_category' => 'fees'])->assertCreated();

        Sanctum::actingAs($this->admin);

        // فيها وليّ أمر، فلولا الاستثناء الصريح لظهرت في تبويب الأهالي.
        $this->assertCount(1, $this->getJson('/api/conversations?type=complaints')->json('data'));
        $this->assertEmpty($this->getJson('/api/conversations?type=guardians')->json('data'));
        $this->assertEmpty($this->getJson('/api/conversations?type=staff')->json('data'));
    }

    public function test_the_guardian_sees_their_own_complaint(): void
    {
        Sanctum::actingAs($this->guardian);
        $id = $this->file(['complaint_category' => 'facilities'])->assertCreated()->json('data.id');

        $this->getJson('/api/conversations?type=complaints')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id);
    }

    public function test_exactly_one_subject_is_required(): void
    {
        Sanctum::actingAs($this->guardian);

        // لا شيء.
        $this->file()->assertStatus(422)->assertJsonValidationErrors('about_staff_id');

        // الاثنان معاً.
        $this->file([
            'about_staff_id' => $this->teacher->id,
            'complaint_category' => 'fees',
        ])->assertStatus(422)->assertJsonValidationErrors('about_staff_id');
    }

    public function test_a_complaint_may_not_name_a_guardian(): void
    {
        Sanctum::actingAs($this->guardian);

        $other = GuardianAccount::for(
            Guardian::factory()->create(['school_id' => $this->school->id]),
        );

        $this->file(['about_staff_id' => $other->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('about_staff_id');
    }

    public function test_staff_cannot_file_a_complaint(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->file(['complaint_category' => 'fees'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    public function test_each_complaint_is_its_own_thread(): void
    {
        Sanctum::actingAs($this->guardian);

        $first = $this->file(['complaint_category' => 'fees'])->assertCreated()->json('data.id');
        $second = $this->file(['complaint_category' => 'canteen'])->assertCreated()->json('data.id');

        // شكوى المقصف لا تُضمّ إلى شكوى الرسوم: كل واقعةٍ حالتها ومعالجتها.
        $this->assertNotSame($first, $second);
        $this->assertSame(2, Conversation::complaintThreads()->count());
    }

    public function test_the_categories_are_served_translated(): void
    {
        Sanctum::actingAs($this->guardian);

        $data = $this->getJson('/api/conversations/complaint-categories')
            ->assertOk()
            ->json('data');

        $this->assertCount(count(ComplaintCategory::cases()), $data);
        $this->assertNotEmpty($data[0]['label']);
    }

    public function test_the_office_can_move_a_complaint_through_its_status(): void
    {
        Sanctum::actingAs($this->guardian);
        $id = $this->file(['about_staff_id' => $this->teacher->id])->assertCreated()->json('data.id');

        Sanctum::actingAs($this->admin);

        $this->patchJson("/api/conversations/{$id}/status", ['status' => 'resolved'])->assertOk();
        $this->assertSame('resolved', Conversation::findOrFail($id)->status->value);
    }
}
