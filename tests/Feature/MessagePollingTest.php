<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * جلب الرسائل الجديدة وحدها — ما يبقي الشات حيّاً حيث لا سوكِت.
 *
 * الاستضافة المشتركة لا تُشغّل Reverb، فالشاشة تسأل كل عشر ثوانٍ «هل وصل
 * شيء؟». وبلا `after_id` يكون ثمن السؤال ثلاثين رسالة بمرفقاتها في كل
 * مرّة — فيصير البديل أغلى من الميزة.
 */
class MessagePollingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $school = School::factory()->create();
        $this->user = User::factory()->role(UserRole::Admin)->create(['school_id' => $school->id]);
        $this->conversation = Conversation::factory()->create(['school_id' => $school->id]);
        $this->conversation->participants()->attach($this->user->id);

        Sanctum::actingAs($this->user);
    }

    private function message(string $body): Message
    {
        return Message::factory()->create([
            'conversation_id' => $this->conversation->id,
            'sender_id' => $this->user->id,
            'body' => $body,
        ]);
    }

    public function test_after_id_returns_only_what_came_later(): void
    {
        $first = $this->message('الأولى');
        $this->message('الثانية');
        $this->message('الثالثة');

        $response = $this->getJson(
            "/api/conversations/{$this->conversation->id}/messages?after_id={$first->id}",
        )->assertOk();

        $bodies = array_column($response->json('data'), 'body');

        $this->assertSame(['الثانية', 'الثالثة'], $bodies);
    }

    public function test_nothing_new_is_an_empty_answer_not_the_whole_page(): void
    {
        $last = $this->message('الأخيرة');

        $response = $this->getJson(
            "/api/conversations/{$this->conversation->id}/messages?after_id={$last->id}",
        )->assertOk();

        // هذه هي الحالة الشائعة في الاستطلاع: لا جديد. لو ردّت بالصفحة
        // كاملة لصارت كلفة السؤال أعلى من قيمة الجواب.
        $this->assertSame([], $response->json('data'));
    }

    public function test_without_after_id_the_page_still_works(): void
    {
        $this->message('الأولى');
        $this->message('الثانية');

        $response = $this->getJson(
            "/api/conversations/{$this->conversation->id}/messages",
        )->assertOk();

        // الفتح الأول يبقى مرقّماً كما كان — لا يكسر العميل القائم.
        $this->assertCount(2, $response->json('data'));
        $this->assertNotNull($response->json('meta.current_page'));
    }
}
