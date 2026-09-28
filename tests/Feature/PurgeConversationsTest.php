<?php

namespace Tests\Feature;

use App\Enums\AttachmentOwner;
use App\Enums\ConversationType;
use App\Enums\UserRole;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\Notification;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * حذف الخيوط التي أسقطها العطل، وإبقاء السليمة.
 */
class PurgeConversationsTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $teacher;

    private User $guardian;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $this->teacher = User::factory()->role(UserRole::Teacher)->create([
            'school_id' => $this->school->id,
        ]);
        $this->guardian = User::factory()->role(UserRole::Guardian)->create([
            'school_id' => $this->school->id,
        ]);
    }

    /** @param  array<int, User>  $participants */
    private function thread(array $participants): Conversation
    {
        $conversation = Conversation::create([
            'school_id' => $this->school->id,
            'type' => ConversationType::Guardians,
            'last_message_at' => now(),
        ]);

        foreach ($participants as $participant) {
            $conversation->participantRecords()->create(['user_id' => $participant->id]);
        }

        $conversation->messages()->create([
            'sender_id' => $participants[0]->id,
            'body' => 'نصّ',
            'sent_at' => now(),
        ]);

        return $conversation;
    }

    public function test_a_scope_must_be_chosen(): void
    {
        $this->thread([$this->teacher]);

        $this->artisan('conversations:purge')->assertFailed();

        $this->assertDatabaseCount('conversations', 1);
    }

    public function test_broken_threads_go_and_healthy_ones_stay(): void
    {
        $broken = $this->thread([$this->teacher]);
        $healthy = $this->thread([$this->teacher, $this->guardian]);

        $this->artisan('conversations:purge --broken --force')->assertSuccessful();

        $this->assertDatabaseMissing('conversations', ['id' => $broken->id]);
        $this->assertDatabaseHas('conversations', ['id' => $healthy->id]);
        // الرسائل تسقط بالتتابع مع خيطها، ولا تُلمَس رسائل السليم.
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $this->thread([$this->teacher]);

        $this->artisan('conversations:purge --broken --dry-run')->assertSuccessful();

        $this->assertDatabaseCount('conversations', 1);
    }

    public function test_all_clears_every_thread(): void
    {
        $this->thread([$this->teacher]);
        $this->thread([$this->teacher, $this->guardian]);

        $this->artisan('conversations:purge --all --force')->assertSuccessful();

        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('conversation_participants', 0);
    }

    public function test_attachment_rows_and_stale_notifications_go_too(): void
    {
        $broken = $this->thread([$this->teacher]);
        $message = $broken->messages()->firstOrFail();

        $attachment = Attachment::create([
            'school_id' => $this->school->id,
            'owner_type' => AttachmentOwner::Message,
            'owner_id' => $message->id,
            'path' => 'attachments/message/x.jpg',
            'name' => 'x.jpg',
        ]);

        $notification = Notification::create([
            'user_id' => $this->teacher->id,
            'type' => 'message_received',
            'title' => 'رسالة',
            'ref_id' => $broken->id,
        ]);

        $this->artisan('conversations:purge --broken --force')->assertSuccessful();

        // جدول المرفقات polymorphic بلا مفتاح أجنبي، فلا يسقط وحده.
        $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
        // وإشعارٌ يشير إلى خيطٍ ذهب يفتح على فراغ.
        $this->assertDatabaseMissing('notifications', ['id' => $notification->id]);
    }

    public function test_the_school_filter_limits_the_damage(): void
    {
        $mine = $this->thread([$this->teacher]);

        $otherSchool = School::factory()->create();
        $stranger = User::factory()->role(UserRole::Teacher)->create([
            'school_id' => $otherSchool->id,
        ]);
        $theirs = Conversation::create([
            'school_id' => $otherSchool->id,
            'type' => ConversationType::Guardians,
            'last_message_at' => now(),
        ]);
        $theirs->participantRecords()->create(['user_id' => $stranger->id]);

        $this->artisan("conversations:purge --broken --force --school={$this->school->id}")
            ->assertSuccessful();

        $this->assertDatabaseMissing('conversations', ['id' => $mine->id]);
        $this->assertDatabaseHas('conversations', ['id' => $theirs->id]);
    }
}
