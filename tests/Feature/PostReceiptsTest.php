<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Enums\TargetScope;
use App\Enums\UserRole;
use App\Models\Guardian;
use App\Models\Post;
use App\Models\PostType;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PostReceiptsTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $author;

    private User $guardian;

    private Post $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $this->author = User::factory()->role(UserRole::Teacher)->create(['school_id' => $this->school->id]);
        $this->guardian = User::factory()->role(UserRole::Guardian)->create(['school_id' => $this->school->id]);
        Guardian::factory()->create(['school_id' => $this->school->id, 'user_id' => $this->guardian->id]);

        $this->post = Post::factory()->create([
            'school_id' => $this->school->id,
            'post_type_id' => PostType::factory()->create(['school_id' => $this->school->id])->id,
            'author_id' => $this->author->id,
            'status' => PostStatus::Published,
            'published_at' => now(),
            'requires_confirmation' => true,
        ]);
        $this->post->targets()->create(['scope' => TargetScope::School->value, 'target_id' => null]);
    }

    public function test_read_is_idempotent_and_confirm_is_recorded(): void
    {
        Sanctum::actingAs($this->guardian);

        $this->postJson("/api/posts/{$this->post->id}/read")->assertOk();
        $this->postJson("/api/posts/{$this->post->id}/read")->assertOk();
        $this->assertDatabaseCount('post_receipts', 1);

        $this->postJson("/api/posts/{$this->post->id}/confirm")->assertOk();
        $this->getJson("/api/posts/{$this->post->id}")
            ->assertOk()
            ->assertJsonPath('data.requires_confirmation', true)
            ->assertJsonMissingPath('data.receipt_stats');
        $this->assertNotNull($this->post->receipts()->first()->confirmed_at);

        // The list carries the reader's own receipt without a per-row query
        // (the author lists their own posts; this guardian has no linked child,
        // so their targeted list is legitimately empty).
        Sanctum::actingAs($this->author);
        $this->postJson("/api/posts/{$this->post->id}/read")->assertOk();
        $rows = $this->getJson('/api/posts?source=mine')->assertOk()->json('data');
        $this->assertNotNull($rows[0]['my_receipt']['read_at']);
        $this->assertNull($rows[0]['my_receipt']['confirmed_at']);
    }

    public function test_confirm_requires_the_flag(): void
    {
        $this->post->update(['requires_confirmation' => false]);

        Sanctum::actingAs($this->guardian);
        $this->postJson("/api/posts/{$this->post->id}/confirm")->assertStatus(422);
    }

    public function test_author_sees_stats_and_receipts_but_guardian_does_not(): void
    {
        Sanctum::actingAs($this->guardian);
        $this->postJson("/api/posts/{$this->post->id}/confirm")->assertOk();
        $this->getJson("/api/posts/{$this->post->id}/receipts")->assertForbidden();

        Sanctum::actingAs($this->author);
        $this->getJson("/api/posts/{$this->post->id}")
            ->assertOk()
            ->assertJsonPath('data.receipt_stats.read', 1)
            ->assertJsonPath('data.receipt_stats.confirmed', 1);

        $this->getJson("/api/posts/{$this->post->id}/receipts")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user_id', $this->guardian->id);

        // Another teacher (not the author, not an admin) gets no receipts.
        $other = User::factory()->role(UserRole::Teacher)->create(['school_id' => $this->school->id]);
        Sanctum::actingAs($other);
        $this->getJson("/api/posts/{$this->post->id}/receipts")->assertForbidden();
    }
}
