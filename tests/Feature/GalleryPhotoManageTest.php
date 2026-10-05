<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Enums\TargetScope;
use App\Enums\UserRole;
use App\Models\Attachment;
use App\Models\Post;
use App\Models\PostType;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * حذف صور المعرض وتعديلها: الحذف يمحو الملف لا الصفّ وحده، والتعديل لمنشور
 * الصور (عنوانه وجمهوره) للإدارة.
 */
class GalleryPhotoManageTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $admin;

    private User $teacher;

    private Post $post;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->school = School::factory()->create();
        $this->admin = User::factory()->role(UserRole::Admin)->create(['school_id' => $this->school->id]);
        $this->teacher = User::factory()->role(UserRole::Teacher)->create(['school_id' => $this->school->id]);

        $this->post = Post::factory()->create([
            'school_id' => $this->school->id,
            'post_type_id' => PostType::factory()->create(['school_id' => $this->school->id, 'key' => 'activity'])->id,
            'author_id' => $this->teacher->id,
            'title' => 'صور جديدة',
            'status' => PostStatus::Published,
            'published_at' => now(),
        ]);
        $this->post->targets()->create(['scope' => TargetScope::School->value, 'target_id' => null]);
    }

    private function upload(User $as): Attachment
    {
        Sanctum::actingAs($as);
        $id = $this->postJson('/api/attachments', [
            'owner_type' => 'post',
            'owner_id' => $this->post->id,
            'file' => UploadedFile::fake()->image('class.jpg'),
        ])->assertCreated()->json('data.id');

        return Attachment::findOrFail($id);
    }

    public function test_deleting_a_photo_removes_its_file(): void
    {
        $photo = $this->upload($this->teacher);
        Storage::disk('public')->assertExists($photo->path);

        $this->deleteJson("/api/attachments/{$photo->id}")->assertOk();

        $this->assertModelMissing($photo);
        Storage::disk('public')->assertMissing($photo->path);
    }

    public function test_a_teacher_cannot_delete_someone_elses_photo_but_the_admin_can(): void
    {
        $photo = $this->upload($this->admin);

        Sanctum::actingAs($this->teacher);
        $this->deleteJson("/api/attachments/{$photo->id}")->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->deleteJson("/api/attachments/{$photo->id}")->assertOk();
    }

    public function test_the_admin_edits_caption_and_audience_of_a_photo_post(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/posts/{$this->post->id}", [
            'title' => 'رحلة الصف التاسع',
            'targets' => [['scope' => 'school']],
        ])->assertOk()->assertJsonPath('data.title', 'رحلة الصف التاسع');
    }
}
