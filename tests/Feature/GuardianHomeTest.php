<?php

namespace Tests\Feature;

use App\Enums\AttachmentOwner;
use App\Enums\PostStatus;
use App\Enums\TargetScope;
use App\Enums\UserRole;
use App\Models\Attachment;
use App\Models\Guardian;
use App\Models\Post;
use App\Models\PostType;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * رئيسية تطبيق الأهالي تعرض ابناً واحداً في كل مرّة: منشوراته، صوره، تقويمه،
 * وعدّادات ما لم يُقرأ. وهذه الاختبارات تحرس أنّ «ابناً واحداً» لا تصير
 * «أيّ طالب في المدرسة» بتغيير رقمٍ في الطلب.
 */
class GuardianHomeTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $guardianUser;

    private User $teacher;

    private Student $firstChild;

    private Student $secondChild;

    private Student $otherChild;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $this->teacher = User::factory()->role(UserRole::Teacher)->create(['school_id' => $this->school->id]);

        $this->firstChild = Student::factory()->create(['school_id' => $this->school->id]);
        $this->secondChild = Student::factory()->create(['school_id' => $this->school->id]);
        $this->otherChild = Student::factory()->create(['school_id' => $this->school->id]);

        $this->guardianUser = User::factory()->role(UserRole::Guardian)->create(['school_id' => $this->school->id]);
        $guardian = Guardian::factory()->create([
            'school_id' => $this->school->id,
            'user_id' => $this->guardianUser->id,
        ]);
        foreach ([$this->firstChild, $this->secondChild] as $child) {
            StudentGuardian::factory()->create(['student_id' => $child->id, 'guardian_id' => $guardian->id]);
        }

        $otherGuardian = Guardian::factory()->create(['school_id' => $this->school->id]);
        StudentGuardian::factory()->create(['student_id' => $this->otherChild->id, 'guardian_id' => $otherGuardian->id]);
    }

    private function postTo(Student $student, string $typeKey = 'general'): Post
    {
        $type = PostType::query()->firstOrCreate(
            ['school_id' => $this->school->id, 'key' => $typeKey],
            PostType::factory()->make(['school_id' => $this->school->id, 'key' => $typeKey])->getAttributes(),
        );
        $post = Post::factory()->create([
            'school_id' => $this->school->id,
            'post_type_id' => $type->id,
            'author_id' => $this->teacher->id,
            'status' => PostStatus::Published,
            'published_at' => now(),
        ]);
        $post->targets()->create(['scope' => TargetScope::Student->value, 'target_id' => $student->id]);

        return $post;
    }

    public function test_posts_narrow_to_one_child(): void
    {
        $first = $this->postTo($this->firstChild);
        $second = $this->postTo($this->secondChild);
        $this->postTo($this->otherChild);

        Sanctum::actingAs($this->guardianUser);

        $ids = collect($this->getJson("/api/posts?student_id={$this->firstChild->id}")->assertOk()->json('data'))->pluck('id');
        $this->assertEquals([$first->id], $ids->all());

        // Without a child: both of theirs, never the other family's.
        $all = collect($this->getJson('/api/posts')->assertOk()->json('data'))->pluck('id')->sort()->values();
        $this->assertEquals(collect([$first->id, $second->id])->sort()->values()->all(), $all->all());
    }

    public function test_asking_for_another_familys_child_returns_nothing(): void
    {
        $this->postTo($this->otherChild);
        // A school-wide post would reach anyone — it must not reach a stranger's id.
        $school = $this->postTo($this->firstChild);
        $school->targets()->delete();
        $school->targets()->create(['scope' => TargetScope::School->value, 'target_id' => null]);

        Sanctum::actingAs($this->guardianUser);

        $this->getJson("/api/posts?student_id={$this->otherChild->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_type_keys_and_private_filters(): void
    {
        $homework = $this->postTo($this->firstChild, 'homework');
        $trip = $this->postTo($this->firstChild, 'trip');
        $classPost = $this->postTo($this->firstChild, 'homework');
        $classPost->targets()->delete();
        $classPost->targets()->create(['scope' => TargetScope::School->value, 'target_id' => null]);

        Sanctum::actingAs($this->guardianUser);

        $hw = collect($this->getJson("/api/posts?student_id={$this->firstChild->id}&type_keys=homework")->json('data'))->pluck('id')->sort()->values();
        $this->assertEquals(collect([$homework->id, $classPost->id])->sort()->values()->all(), $hw->all());

        $private = collect($this->getJson("/api/posts?student_id={$this->firstChild->id}&private=1")->json('data'))->pluck('id')->sort()->values();
        $this->assertEquals(collect([$homework->id, $trip->id])->sort()->values()->all(), $private->all());
    }

    public function test_gallery_only_shows_images_of_posts_reaching_the_child(): void
    {
        $mine = $this->postTo($this->firstChild);
        $theirs = $this->postTo($this->otherChild);

        $visible = Attachment::factory()->create(['school_id' => $this->school->id, 'owner_type' => AttachmentOwner::Post, 'owner_id' => $mine->id]);
        Attachment::factory()->create(['school_id' => $this->school->id, 'owner_type' => AttachmentOwner::Post, 'owner_id' => $theirs->id]);
        // A chat image shares the owner id by coincidence — still not a post.
        Attachment::factory()->create(['school_id' => $this->school->id, 'owner_type' => AttachmentOwner::Message, 'owner_id' => $mine->id]);

        Sanctum::actingAs($this->guardianUser);

        $ids = collect($this->getJson('/api/gallery')->assertOk()->json('data'))->pluck('id');
        $this->assertEquals([$visible->id], $ids->all());

        $this->getJson("/api/gallery?student_id={$this->secondChild->id}")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_calendar_is_one_own_child(): void
    {
        $this->postTo($this->firstChild, 'trip');
        $this->postTo($this->otherChild, 'trip');

        Sanctum::actingAs($this->guardianUser);

        $this->getJson('/api/calendar')->assertUnprocessable();
        $this->getJson("/api/calendar?student_id={$this->otherChild->id}")->assertNotFound();

        $events = $this->getJson("/api/calendar?student_id={$this->firstChild->id}")
            ->assertOk()
            ->json('data.events');
        $this->assertCount(1, collect($events)->where('type', 'post'));
    }

    public function test_digest_counts_unread_per_child(): void
    {
        $read = $this->postTo($this->firstChild, 'homework');
        $this->postTo($this->firstChild, 'homework');
        $this->postTo($this->firstChild, 'trip');

        Sanctum::actingAs($this->guardianUser);
        $this->postJson("/api/posts/{$read->id}/read")->assertOk();

        $children = collect($this->getJson('/api/guardian/digest')->assertOk()->json('data.children'))
            ->keyBy('student.id');

        $first = $children[$this->firstChild->id]['unread'];
        $this->assertSame(2, $first['all']);
        $this->assertSame(1, $first['homework']);
        $this->assertSame(1, $first['events']);
        $this->assertSame(2, $first['notes']);
        $this->assertSame(0, $children[$this->secondChild->id]['unread']['all']);
    }

    public function test_guardian_school_card(): void
    {
        Sanctum::actingAs($this->guardianUser);

        $this->getJson('/api/guardian/school')
            ->assertOk()
            ->assertJsonPath('data.school.id', $this->school->id)
            ->assertJsonCount(7, 'data.days');

        Sanctum::actingAs($this->teacher);
        $this->getJson('/api/guardian/school')->assertForbidden();
    }
}
