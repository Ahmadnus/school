<?php

namespace Tests\Feature;

use App\Enums\ConversationType;
use App\Enums\UserRole;
use App\Models\Conversation;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * تبويب «الكادر» لمحادثات الموظّفين وحدهم.
 *
 * العلّة كانت أنّ `type` يصل من العميل: خيطٌ يفتحه وليّ أمر وهو على تبويب
 * الكادر كان يُخزَّن `staff` فيظهر بين المحادثات الداخلية.
 */
class ConversationTabScopeTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $teacher;

    private User $admin;

    private User $guardian;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $this->teacher = User::factory()->role(UserRole::Teacher)->create(['school_id' => $this->school->id]);
        $this->admin = User::factory()->role(UserRole::Admin)->create(['school_id' => $this->school->id]);
        $this->guardian = User::factory()->role(UserRole::Guardian)->create(['school_id' => $this->school->id]);
    }

    /** @param  array<int, User>  $participants */
    private function thread(ConversationType $type, array $participants, ?Student $student = null): Conversation
    {
        $conversation = Conversation::create([
            'school_id' => $this->school->id,
            'type' => $type,
            'student_id' => $student?->id,
            'last_message_at' => now(),
        ]);

        foreach ($participants as $participant) {
            $conversation->participantRecords()->create(['user_id' => $participant->id]);
        }

        return $conversation;
    }

    public function test_a_guardian_thread_stored_as_staff_still_lands_in_the_parents_tab(): void
    {
        // الصفّ المعطوب كما هو في الإنتاج: نوعه `staff` وفيه وليّ أمر.
        $misfiled = $this->thread(ConversationType::Staff, [$this->guardian, $this->teacher]);
        $internal = $this->thread(ConversationType::Staff, [$this->teacher, $this->admin]);

        Sanctum::actingAs($this->teacher);

        $staffTab = $this->getJson('/api/conversations?type=staff')->assertOk()->json('data.*.id');
        $this->assertSame([$internal->id], $staffTab);

        $parentsTab = $this->getJson('/api/conversations?type=guardians')->assertOk()->json('data.*.id');
        $this->assertSame([$misfiled->id], $parentsTab);
    }

    public function test_staff_to_staff_threads_keep_showing_in_the_staff_tab(): void
    {
        $internal = $this->thread(ConversationType::Staff, [$this->teacher, $this->admin]);

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/conversations?type=staff')
            ->assertOk()
            ->assertJsonPath('data.0.id', $internal->id)
            ->assertJsonCount(1, 'data');
    }

    public function test_every_thread_belongs_to_exactly_one_tab(): void
    {
        $this->thread(ConversationType::Staff, [$this->teacher, $this->admin]);
        $this->thread(ConversationType::Guardians, [$this->teacher, $this->guardian]);
        $this->thread(ConversationType::Staff, [$this->teacher, $this->guardian]);

        Sanctum::actingAs($this->teacher);

        $staff = $this->getJson('/api/conversations?type=staff')->json('data.*.id');
        $guardians = $this->getJson('/api/conversations?type=guardians')->json('data.*.id');

        $this->assertCount(3, array_merge($staff, $guardians));
        $this->assertEmpty(array_intersect($staff, $guardians));
    }

    public function test_a_guardian_cannot_open_a_staff_thread(): void
    {
        Sanctum::actingAs($this->guardian);

        $this->postJson('/api/conversations', [
            'type' => 'staff',
            'participant_ids' => [$this->teacher->id],
            'body' => 'مرحباً',
        ])->assertStatus(422)->assertJsonValidationErrors('type');
    }

    public function test_a_guardian_may_not_be_added_to_a_staff_thread(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/conversations', [
            'type' => 'staff',
            'participant_ids' => [$this->admin->id, $this->guardian->id],
            'body' => 'اجتماع',
        ])->assertStatus(422)->assertJsonValidationErrors('participant_ids');
    }

    public function test_staff_may_still_open_an_internal_thread(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/conversations', [
            'type' => 'staff',
            'participant_ids' => [$this->admin->id],
            'body' => 'اجتماع',
        ])->assertCreated();
    }
}
