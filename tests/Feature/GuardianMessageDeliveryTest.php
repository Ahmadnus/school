<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Conversation;
use App\Models\Guardian;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Services\GuardianAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * وصول المراسلة إلى تطبيق الطلاب/الأهالي.
 *
 * حساب وليّ الأمر لا يُنشأ إلاّ عند أوّل دخول برمز، فـ`guardians.user_id`
 * يبقى فارغاً قبله. وكان هذا يُسقط المراسلة صامتةً: الموظّف يفتح خيطاً عن
 * طالب فلا يجد الخادم لوليّ أمره حساباً، فيُحفظ الخيط بمشاركٍ واحد هو
 * المرسِل — لا يصل شيئاً ولا يُنشئ إشعاراً.
 */
class GuardianMessageDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $teacher;

    private Student $student;

    private Guardian $guardian;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $this->teacher = User::factory()->role(UserRole::Teacher)->create([
            'school_id' => $this->school->id,
        ]);
        $this->student = Student::factory()->create(['school_id' => $this->school->id]);

        // وليّ أمرٍ لم يدخل التطبيق بعد: لا حساب له.
        $this->guardian = Guardian::factory()->create([
            'school_id' => $this->school->id,
            'user_id' => null,
        ]);
        $this->student->guardians()->attach($this->guardian->id, ['relation' => 'father', 'is_primary' => true]);
    }

    public function test_a_staff_message_reaches_a_guardian_who_never_signed_in(): void
    {
        Sanctum::actingAs($this->teacher);

        $id = $this->postJson('/api/conversations', [
            'type' => 'guardians',
            'student_id' => $this->student->id,
            'body' => 'ابنكم تفوّق اليوم',
        ])->assertCreated()->json('data.id');

        $conversation = Conversation::findOrFail($id);

        // صار له حساب، وهو طرفٌ في الخيط.
        $this->guardian->refresh();
        $this->assertNotNull($this->guardian->user_id);
        $this->assertTrue(
            $conversation->participantRecords()
                ->where('user_id', $this->guardian->user_id)
                ->exists(),
        );
    }

    public function test_that_message_also_raises_a_notification_for_the_guardian(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/conversations', [
            'type' => 'guardians',
            'student_id' => $this->student->id,
            'body' => 'ابنكم تفوّق اليوم',
        ])->assertCreated();

        $this->guardian->refresh();

        // الإشعار كان يسقط مع المشارك: لا مشارك، فلا أحد يُشعَر.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->guardian->user_id,
            'type' => 'message_received',
        ]);
    }

    public function test_the_guardian_then_sees_the_thread_in_their_app(): void
    {
        Sanctum::actingAs($this->teacher);

        $id = $this->postJson('/api/conversations', [
            'type' => 'guardians',
            'student_id' => $this->student->id,
            'body' => 'ابنكم تفوّق اليوم',
        ])->assertCreated()->json('data.id');

        $this->guardian->refresh();
        Sanctum::actingAs($this->guardian->user);

        $this->getJson('/api/conversations?type=guardians')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id);
    }

    public function test_a_guardian_may_write_to_a_chosen_teacher(): void
    {
        $account = GuardianAccount::for($this->guardian);
        Sanctum::actingAs($account);

        $id = $this->postJson('/api/conversations', [
            'type' => 'guardians',
            'student_id' => $this->student->id,
            'participant_ids' => [$this->teacher->id],
            'body' => 'أريد موعداً',
        ])->assertCreated()->json('data.id');

        // الأستاذ المختار هو الطرف، لا المكتب.
        $this->assertTrue(
            Conversation::findOrFail($id)->participantRecords()
                ->where('user_id', $this->teacher->id)
                ->exists(),
        );

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->teacher->id,
            'type' => 'message_received',
        ]);
    }

    public function test_a_guardian_leaving_the_recipient_empty_reaches_the_office(): void
    {
        $admin = User::factory()->role(UserRole::Admin)->create([
            'school_id' => $this->school->id,
        ]);

        Sanctum::actingAs(GuardianAccount::for($this->guardian));

        $id = $this->postJson('/api/conversations', [
            'type' => 'guardians',
            'student_id' => $this->student->id,
            'body' => 'استفسار',
        ])->assertCreated()->json('data.id');

        $this->assertTrue(
            Conversation::findOrFail($id)->participantRecords()
                ->where('user_id', $admin->id)
                ->exists(),
        );
    }

    public function test_a_guardian_may_not_write_to_another_guardian(): void
    {
        $other = Guardian::factory()->create(['school_id' => $this->school->id]);
        $otherAccount = GuardianAccount::for($other);

        Sanctum::actingAs(GuardianAccount::for($this->guardian));

        $this->postJson('/api/conversations', [
            'type' => 'guardians',
            'student_id' => $this->student->id,
            'participant_ids' => [$otherAccount->id],
            'body' => 'مرحباً',
        ])->assertStatus(422)->assertJsonValidationErrors('participant_ids');
    }

    public function test_an_existing_guardian_account_is_reused_not_duplicated(): void
    {
        $existing = User::factory()->role(UserRole::Guardian)->create([
            'school_id' => $this->school->id,
            'phone' => $this->guardian->phone,
        ]);

        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/conversations', [
            'type' => 'guardians',
            'student_id' => $this->student->id,
            'body' => 'مرحباً',
        ])->assertCreated();

        $this->guardian->refresh();

        // رقم الهاتف فريد داخل المدرسة: لا يُصنع حسابٌ ثانٍ لنفس الرقم.
        $this->assertSame($existing->id, $this->guardian->user_id);
        $this->assertSame(
            1,
            User::where('school_id', $this->school->id)
                ->where('phone', $this->guardian->phone)
                ->count(),
        );
    }
}
