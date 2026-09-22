<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Conversation;
use App\Models\Guardian;
use App\Models\Notification;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * خيطٌ واحد لكل طرفين — لا خيط جديد مع كل رسالة افتتاحية.
 *
 * العطل كما رآه المستخدم: يفتح وليّ الأمر التطبيق فيجد ثلاث محادثات
 * بالاسم نفسه، فيقرأ الردّ في واحدة ويكتب في أخرى.
 */
class ConversationReuseTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $admin;

    private User $guardianUser;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $this->admin = User::factory()->role(UserRole::Admin)->create(['school_id' => $this->school->id]);
        $this->guardianUser = User::factory()->role(UserRole::Guardian)->create(['school_id' => $this->school->id]);

        $this->student = Student::factory()->create(['school_id' => $this->school->id]);
        $guardian = Guardian::factory()->create([
            'school_id' => $this->school->id,
            'user_id' => $this->guardianUser->id,
        ]);
        StudentGuardian::factory()->create([
            'student_id' => $this->student->id,
            'guardian_id' => $guardian->id,
        ]);
    }

    private function open(string $body): array
    {
        return $this->postJson('/api/conversations', [
            'type' => 'guardians',
            'student_id' => $this->student->id,
            'participant_ids' => [$this->admin->id],
            'body' => $body,
        ])->json();
    }

    public function test_writing_twice_keeps_one_thread(): void
    {
        Sanctum::actingAs($this->guardianUser);

        $first = $this->open('السلام عليكم');
        $second = $this->open('رسالة ثانية');

        $this->assertSame(1, Conversation::count(), 'صار خيطان للطرفين نفسهما');
        $this->assertSame($first['data']['id'], $second['data']['id']);
        $this->assertSame(2, Conversation::firstOrFail()->messages()->count());
    }

    public function test_a_different_child_gets_its_own_thread(): void
    {
        $other = Student::factory()->create(['school_id' => $this->school->id]);
        $guardian = Guardian::query()->where('user_id', $this->guardianUser->id)->firstOrFail();
        StudentGuardian::factory()->create([
            'student_id' => $other->id,
            'guardian_id' => $guardian->id,
        ]);

        Sanctum::actingAs($this->guardianUser);

        $this->open('عن الابن الأول');
        $this->postJson('/api/conversations', [
            'type' => 'guardians',
            'student_id' => $other->id,
            'participant_ids' => [$this->admin->id],
            'body' => 'عن الابن الثاني',
        ])->assertCreated();

        // الدمج بالأطراف وحدها كان سيخلط شأن ابنين في خيط واحد.
        $this->assertSame(2, Conversation::count());
    }

    public function test_a_different_staff_member_gets_its_own_thread(): void
    {
        $teacher = User::factory()->role(UserRole::Teacher)->create(['school_id' => $this->school->id]);

        Sanctum::actingAs($this->guardianUser);

        $this->open('إلى الإدارة');
        $this->postJson('/api/conversations', [
            'type' => 'guardians',
            'student_id' => $this->student->id,
            'participant_ids' => [$teacher->id],
            'body' => 'إلى المعلّم',
        ])->assertCreated();

        $this->assertSame(2, Conversation::count());
    }

    public function test_the_opening_message_notifies_the_other_side(): void
    {
        Sanctum::actingAs($this->guardianUser);

        $this->open('السلام عليكم');

        // كانت الرسالة الأولى تُحفظ بلا إشعار: لا يعلم بها أحد حتى يفتح
        // قائمة المحادثات مصادفةً.
        $this->assertSame(
            1,
            Notification::where('user_id', $this->admin->id)
                ->where('type', 'message_received')
                ->count(),
        );

        // ولا يُشعَر كاتبها بفعل نفسه.
        $this->assertSame(
            0,
            Notification::where('user_id', $this->guardianUser->id)->count(),
        );
    }
}
