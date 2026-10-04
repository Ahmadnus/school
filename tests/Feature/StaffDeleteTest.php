<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Enums\UserRole;
use App\Models\Post;
use App\Models\PostType;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * حذف الكادر لا يمحو ما كتبه عند الأهل: من له منشورات أو رسائل يُوقَف ولا
 * يُحذف، ومن لا أثر له يُحذف.
 */
class StaffDeleteTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $this->admin = User::factory()->role(UserRole::Admin)->create(['school_id' => $this->school->id]);
        Sanctum::actingAs($this->admin);
    }

    public function test_a_staff_member_without_content_is_deleted(): void
    {
        $teacher = User::factory()->role(UserRole::Teacher)->create(['school_id' => $this->school->id]);

        $this->deleteJson("/api/users/{$teacher->id}")->assertOk();

        $this->assertModelMissing($teacher);
    }

    public function test_a_teacher_who_published_is_kept_and_their_posts_survive(): void
    {
        $teacher = User::factory()->role(UserRole::Teacher)->create(['school_id' => $this->school->id]);
        $post = Post::factory()->create([
            'school_id' => $this->school->id,
            'post_type_id' => PostType::factory()->create(['school_id' => $this->school->id])->id,
            'author_id' => $teacher->id,
            'status' => PostStatus::Published,
            'published_at' => now(),
        ]);

        $this->deleteJson("/api/users/{$teacher->id}")
            ->assertUnprocessable()
            ->assertJsonPath('data.code', 'user_has_content');

        $this->assertModelExists($teacher);
        $this->assertModelExists($post);
    }

    public function test_deactivating_instead_works_and_editing_keeps_the_password(): void
    {
        $teacher = User::factory()->role(UserRole::Teacher)->create(['school_id' => $this->school->id]);
        $hash = $teacher->password;

        $this->putJson("/api/users/{$teacher->id}", [
            'first_name' => 'اسم',
            'last_name' => 'معدّل',
            'status' => 'inactive',
        ])->assertOk();

        $teacher->refresh();
        $this->assertSame('inactive', $teacher->status->value);
        $this->assertSame('اسم', $teacher->first_name);
        $this->assertSame($hash, $teacher->password);
    }

    public function test_nobody_deletes_themselves(): void
    {
        $this->deleteJson("/api/users/{$this->admin->id}")->assertForbidden();
    }
}
