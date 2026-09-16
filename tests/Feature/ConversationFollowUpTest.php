<?php

namespace Tests\Feature;

use App\Enums\ConversationType;
use App\Enums\UserRole;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConversationFollowUpTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $admin;

    private User $guardian;

    private Conversation $thread;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $this->admin = User::factory()->role(UserRole::Admin)->create(['school_id' => $this->school->id]);
        $this->guardian = User::factory()->role(UserRole::Guardian)->create(['school_id' => $this->school->id]);

        $this->thread = Conversation::factory()->create([
            'school_id' => $this->school->id,
            'type' => ConversationType::Guardians,
        ]);
        foreach ([$this->admin, $this->guardian] as $user) {
            ConversationParticipant::query()->create([
                'conversation_id' => $this->thread->id,
                'user_id' => $user->id,
            ]);
        }
    }

    public function test_staff_flags_a_thread_and_the_due_filter_finds_it(): void
    {
        Sanctum::actingAs($this->admin);

        $this->patchJson("/api/conversations/{$this->thread->id}/follow-up", [
            'needs_follow_up' => true,
            'follow_up_at' => now()->subDay()->toDateString(),
            'is_important' => true,
            'follow_up_note' => 'الأهل طلبوا اجتماعاً',
        ])->assertOk()
            ->assertJsonPath('data.needs_follow_up', true)
            ->assertJsonPath('data.is_important', true)
            ->assertJsonPath('data.follow_up_overdue', true)
            ->assertJsonPath('data.follow_up_note', 'الأهل طلبوا اجتماعاً');

        $this->getJson('/api/conversations?follow_up_due=1')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/conversations?important=1')->assertOk()->assertJsonCount(1, 'data');

        // Clearing the flag also clears its date and note.
        $this->patchJson("/api/conversations/{$this->thread->id}/follow-up", ['needs_follow_up' => false])
            ->assertOk()
            ->assertJsonPath('data.follow_up_at', null)
            ->assertJsonPath('data.follow_up_note', null);
        $this->getJson('/api/conversations?needs_follow_up=1')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_guardian_cannot_flag_and_note_is_validated(): void
    {
        Sanctum::actingAs($this->guardian);
        $this->patchJson("/api/conversations/{$this->thread->id}/follow-up", ['needs_follow_up' => true])
            ->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/conversations/{$this->thread->id}/follow-up", [
            'follow_up_note' => str_repeat('x', 501),
        ])->assertStatus(422)->assertJsonValidationErrors(['follow_up_note']);

        // A staff member who is not a participant cannot flag someone else's thread.
        $outsider = User::factory()->role(UserRole::Teacher)->create(['school_id' => $this->school->id]);
        Sanctum::actingAs($outsider);
        $this->patchJson("/api/conversations/{$this->thread->id}/follow-up", ['needs_follow_up' => true])
            ->assertForbidden();
    }
}
