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
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * عدّاد غير المقروء: صحّته أوّلاً، ثم ألّا يكلّف استعلاماً لكل محادثة.
 */
class ConversationUnreadCountTest extends TestCase
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

    /** محادثة بيني وبين الآخر، مع رسائل منه وتاريخ قراءة اختياري. */
    private function thread(int $fromOther, ?string $readAt = null): Conversation
    {
        $thread = Conversation::factory()->create([
            'school_id' => $this->school->id,
            'type' => ConversationType::Staff,
        ]);

        ConversationParticipant::query()->create([
            'conversation_id' => $thread->id,
            'user_id' => $this->me->id,
            'last_read_at' => $readAt,
        ]);
        ConversationParticipant::query()->create([
            'conversation_id' => $thread->id,
            'user_id' => $this->other->id,
        ]);

        for ($i = 0; $i < $fromOther; $i++) {
            Message::factory()->create([
                'conversation_id' => $thread->id,
                'sender_id' => $this->other->id,
                'sent_at' => now()->addMinutes($i + 1),
            ]);
        }

        return $thread;
    }

    public function test_messages_from_the_other_side_count_as_unread(): void
    {
        $this->thread(fromOther: 3);

        Sanctum::actingAs($this->me);

        $row = $this->getJson('/api/conversations')->assertOk()->json('data.0');

        $this->assertSame(3, $row['unread_count']);
    }

    public function test_my_own_messages_are_never_unread(): void
    {
        $thread = $this->thread(fromOther: 0);
        Message::factory()->create([
            'conversation_id' => $thread->id,
            'sender_id' => $this->me->id,
            'sent_at' => now(),
        ]);

        Sanctum::actingAs($this->me);

        $row = $this->getJson('/api/conversations')->assertOk()->json('data.0');

        $this->assertSame(0, $row['unread_count']);
    }

    public function test_reading_the_thread_clears_what_came_before(): void
    {
        // قرأتُ بعد الرسالة الأولى؛ تبقى الثانية والثالثة غير مقروءتين.
        $thread = $this->thread(fromOther: 0, readAt: now()->addMinutes(1)->toDateTimeString());
        foreach ([0, 2, 3] as $i) {
            Message::factory()->create([
                'conversation_id' => $thread->id,
                'sender_id' => $this->other->id,
                'sent_at' => now()->addMinutes($i),
            ]);
        }

        Sanctum::actingAs($this->me);

        $row = $this->getJson('/api/conversations')->assertOk()->json('data.0');

        $this->assertSame(2, $row['unread_count']);
    }

    public function test_the_list_does_not_query_once_per_conversation(): void
    {
        for ($i = 0; $i < 2; $i++) {
            $this->thread(fromOther: 2);
        }

        Sanctum::actingAs($this->me);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/conversations')->assertOk();
        $few = count(DB::getQueryLog());
        DB::disableQueryLog();

        for ($i = 0; $i < 6; $i++) {
            $this->thread(fromOther: 2);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/conversations')->assertOk();
        $many = count(DB::getQueryLog());
        DB::disableQueryLog();

        // N+1 يُكتشف بالنمو لا بالعدد: أربع محادثات إضافية يجب ألّا تضيف
        // أربعة استعلامات. قائمة المراسلات تُفتح كل دقيقة.
        $this->assertSame(
            $few,
            $many,
            "استعلامات القائمة نمت من {$few} إلى {$many} بزيادة المحادثات",
        );
    }
}
