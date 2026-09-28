<?php

namespace Tests\Feature;

use App\Enums\ConversationType;
use App\Enums\UserRole;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * علامة «قُرئت» — هل شاهد الطرف الآخر رسالتي؟
 *
 * تُشتقّ من `last_read_at` القائم، بلا جدول جديد: `others_last_read_at` هو
 * أقدم لحظة قرأ فيها كلّ من عداي، ورسالتي «قُرئت» إن أُرسلت قبله. والصدق هو
 * الحاكم — كما يرفض النظام ادّعاء «سُلّمت»، لا يُدّعى أنها قُرئت حتى يفتح
 * الطرف الآخر المحادثة فعلاً.
 */
class MessageReadReceiptTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $me;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $this->me = User::factory()->role(UserRole::Admin)->create(['school_id' => $this->school->id]);
        $this->other = User::factory()->role(UserRole::Teacher)->create(['school_id' => $this->school->id]);
    }

    private function thread(?string $myRead = null, ?string $otherRead = null): Conversation
    {
        $thread = Conversation::factory()->create([
            'school_id' => $this->school->id,
            'type' => ConversationType::Staff,
        ]);

        ConversationParticipant::query()->create([
            'conversation_id' => $thread->id,
            'user_id' => $this->me->id,
            'last_read_at' => $myRead,
        ]);
        ConversationParticipant::query()->create([
            'conversation_id' => $thread->id,
            'user_id' => $this->other->id,
            'last_read_at' => $otherRead,
        ]);

        return $thread;
    }

    public function test_the_other_partys_read_time_is_exposed_to_me(): void
    {
        $readAt = now()->subMinutes(5);
        $thread = $this->thread(otherRead: $readAt->toDateTimeString());

        Sanctum::actingAs($this->me);

        $watermark = $this->getJson("/api/conversations/{$thread->id}")
            ->assertOk()
            ->json('data.others_last_read_at');

        $this->assertNotNull($watermark);
        $this->assertSame(
            $readAt->toIso8601String(),
            Carbon::parse($watermark)->toIso8601String(),
        );
    }

    public function test_no_watermark_until_the_other_side_opens_the_thread(): void
    {
        // قرأتُ أنا، والطرف الآخر لم يفتح بعد.
        $thread = $this->thread(myRead: now()->toDateTimeString(), otherRead: null);

        Sanctum::actingAs($this->me);

        $this->getJson("/api/conversations/{$thread->id}")
            ->assertOk()
            ->assertJsonPath('data.others_last_read_at', null);
    }

    public function test_marking_read_lets_the_sender_see_the_read_mark(): void
    {
        $thread = $this->thread();

        // أرسلتُ رسالة.
        Sanctum::actingAs($this->me);
        $this->postJson("/api/conversations/{$thread->id}/messages", ['body' => 'سلام'])
            ->assertCreated();

        // قبل أن يفتحها الآخر: لا علامة قراءة.
        $this->assertNull(
            $this->getJson("/api/conversations/{$thread->id}")->json('data.others_last_read_at'),
        );

        // يفتحها الطرف الآخر.
        Sanctum::actingAs($this->other);
        $this->postJson("/api/conversations/{$thread->id}/read")->assertOk();

        // الآن أرى أنها قُرئت، بعد وقت إرسال رسالتي.
        Sanctum::actingAs($this->me);
        $watermark = $this->getJson("/api/conversations/{$thread->id}")
            ->json('data.others_last_read_at');

        $message = Message::query()->where('conversation_id', $thread->id)->first();
        $this->assertNotNull($watermark);
        $this->assertTrue(
            Carbon::parse($watermark)->gte($message->sent_at),
            'رسالتي أُرسلت قبل أن يقرأ الطرف الآخر، فيجب أن تظهر مقروءة',
        );
    }

    public function test_a_group_thread_is_read_only_when_everyone_else_has_read(): void
    {
        $third = User::factory()->role(UserRole::Teacher)->create(['school_id' => $this->school->id]);

        $thread = Conversation::factory()->create([
            'school_id' => $this->school->id,
            'type' => ConversationType::Staff,
        ]);
        ConversationParticipant::query()->create([
            'conversation_id' => $thread->id, 'user_id' => $this->me->id, 'last_read_at' => now(),
        ]);
        ConversationParticipant::query()->create([
            'conversation_id' => $thread->id, 'user_id' => $this->other->id,
            'last_read_at' => now()->subMinute(),
        ]);
        // الثالث لم يقرأ بعد.
        ConversationParticipant::query()->create([
            'conversation_id' => $thread->id, 'user_id' => $third->id, 'last_read_at' => null,
        ]);

        Sanctum::actingAs($this->me);

        // ما دام واحدٌ لم يقرأ، لا تُدّعى القراءة للمجموعة.
        $this->getJson("/api/conversations/{$thread->id}")
            ->assertOk()
            ->assertJsonPath('data.others_last_read_at', null);

        // يقرأ الثالث: العلامة تصير أقدم قراءةٍ بين الآخرين (وقت الطرف الثاني).
        ConversationParticipant::query()
            ->where('conversation_id', $thread->id)
            ->where('user_id', $third->id)
            ->update(['last_read_at' => now()->addMinute()]);

        $watermark = $this->getJson("/api/conversations/{$thread->id}")
            ->json('data.others_last_read_at');
        $this->assertNotNull($watermark);
    }
}
